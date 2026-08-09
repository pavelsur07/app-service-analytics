<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Разбор /v2/posting/fbo/list в SalesFact (ADR-009). Domain, не
 * Infrastructure: применяет правила ADR-009 (состав source_row_id,
 * бизнес-дата, деньги), не делает ни одного вызова наружу — ни HTTP,
 * ни БД. Отдельный шаг от сохранения raw (ADR-006): неудача здесь
 * не теряет сырьё, оно уже сохранено раньше.
 */
final class OzonPostingFboListParser
{
    private const string TIMEZONE = 'Europe/Moscow';
    private const string CURRENCY = 'RUB';

    /**
     * @return list<SalesFact>
     */
    public function parse(string $rawBody, Uuid $companyId, Uuid $marketplaceAccountId, Uuid $rawDocumentId): array
    {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['result']) || !\is_array($decoded['result'])) {
            throw new \UnexpectedValueException('Ozon /v2/posting/fbo/list response must contain a "result" array.');
        }

        $timezone = new \DateTimeZone(self::TIMEZONE);
        $facts = [];

        foreach ($decoded['result'] as $posting) {
            if (!\is_array($posting)) {
                throw new \UnexpectedValueException('Ozon posting entry must be an object.');
            }

            foreach ($this->postingToFacts($posting, $companyId, $marketplaceAccountId, $rawDocumentId, $timezone) as $fact) {
                $facts[] = $fact;
            }
        }

        return $facts;
    }

    /**
     * @param array<array-key, mixed> $posting
     *
     * @return list<SalesFact>
     */
    private function postingToFacts(
        array $posting,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        Uuid $rawDocumentId,
        \DateTimeZone $timezone,
    ): array {
        $postingNumber = self::requireString($posting, 'posting_number');
        $status = self::requireString($posting, 'status');
        $inProcessAt = self::requireString($posting, 'in_process_at');

        // Бизнес-дата = дата заказа (in_process_at), не доставки (ADR-009):
        // план-факт по определению сверяется с текущим планом, а не
        // с историческим, задержка на цикл доставки делала бы свежие
        // данные пустыми.
        $businessDate = (new \DateTimeImmutable($inProcessAt))
            ->setTimezone($timezone)
            ->setTime(0, 0);

        /** @var array<string, mixed> $financialBySku */
        $financialBySku = [];
        $financialData = $posting['financial_data'] ?? [];
        $financialProducts = \is_array($financialData) ? ($financialData['products'] ?? []) : [];
        if (\is_array($financialProducts)) {
            foreach ($financialProducts as $financialProduct) {
                if (!\is_array($financialProduct)) {
                    continue;
                }
                $productId = self::requireString($financialProduct, 'product_id');
                $financialBySku[$productId] = $financialProduct;
            }
        }

        $products = $posting['products'] ?? [];
        if (!\is_array($products)) {
            throw new \UnexpectedValueException("Posting {$postingNumber}: \"products\" must be an array.");
        }

        $facts = [];
        foreach ($products as $product) {
            if (!\is_array($product)) {
                throw new \UnexpectedValueException("Posting {$postingNumber}: product entry must be an object.");
            }

            $sku = self::requireString($product, 'sku');
            $quantity = self::requireInt($product, 'quantity');

            if (!isset($financialBySku[$sku])) {
                throw new \UnexpectedValueException("Posting {$postingNumber}: no financial_data.products entry for sku {$sku}.");
            }
            /** @var array<string, mixed> $financial */
            $financial = $financialBySku[$sku];

            $facts[] = SalesFact::normalize(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                // ADR-009: конкатенация, не хэш — читается при отладке
                // без обратного преобразования. Ozon не повторяет sku
                // внутри одного posting_number (проверено на фикстуре
                // пакета 0 перед этим пакетом).
                sourceRowId: "{$postingNumber}|{$sku}",
                businessDate: $businessDate,
                status: $status,
                marketplaceSku: $sku,
                quantity: $quantity,
                amount: self::money(self::requireNumber($financial, 'price')),
                commissionAmount: self::money(self::requireNumber($financial, 'commission_amount')),
                rawDocumentId: $rawDocumentId,
            );
        }

        return $facts;
    }

    /**
     * Ozon отдаёt суммы JSON-числом (float) — минорные единицы Money не
     * принимают float (ADR-004). number_format фиксирует ровно 2 знака
     * после запятой без домножения самого числа (не (int)($x * 100) —
     * умножение float размывает копейки на значениях вроде -1152.96);
     * дальше — целочисленный разбор уже зафиксированной строки.
     */
    private static function money(float $decimal): Money
    {
        $formatted = number_format($decimal, 2, '.', '');
        $negative = str_starts_with($formatted, '-');
        [$whole, $fraction] = explode('.', ltrim($formatted, '-'));
        $minor = ((int) $whole * 100) + (int) $fraction;

        return Money::ofMinor($negative ? -$minor : $minor, self::CURRENCY);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) && !\is_int($value)) {
            throw new \UnexpectedValueException("Expected field \"{$key}\" to be present and scalar.");
        }

        return (string) $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireInt(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException("Expected field \"{$key}\" to be an integer.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireNumber(array $data, string $key): float
    {
        $value = $data[$key] ?? null;
        if (!\is_int($value) && !\is_float($value)) {
            throw new \UnexpectedValueException("Expected field \"{$key}\" to be numeric.");
        }

        return (float) $value;
    }
}
