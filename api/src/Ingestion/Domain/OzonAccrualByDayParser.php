<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

/**
 * Разбор /v1/finance/accrual/by-day в расходы (ADR-012). Domain,
 * не Infrastructure: ни одного вызова наружу — ни HTTP, ни БД.
 * Проверяется на зафиксированном ответе настоящего кабинета (§9).
 *
 * Одно начисление раскладывается в ноль или больше строк расхода —
 * по строке на пару «товар + тип начисления». Три категории:
 *
 *   POSTING   расходы по отправлению: логистика, доставка до места
 *             выдачи, обратная логистика, обработка возвратов;
 *   ITEM      по товару без отправления: эквайринг, упаковка;
 *   NON_ITEM  без товара вовсе: реклама, хранение, досрочная выплата —
 *             артикул пустой строкой.
 *
 * Блок `commission` у товара отправления — продажа или возврат — даёт
 * ещё две строки: выручку (`sale_amount`, код 0) и вознаграждение
 * за продажу (`sale_commission`, код 69). Юнит-экономика считается
 * по начислениям и `sales_fact` не читает, поэтому двойного счёта нет
 * (ADR-036).
 *
 * Сумма строк начисления обязана равняться его `total_amount`. Иначе
 * часть денег разбор не понял, и документ отклоняется: начисление,
 * пропущенное молча, занижает или завышает прибыль, ничем себя
 * не выдав (ADR-036 п. 5).
 */
final class OzonAccrualByDayParser
{
    /**
     * Категории, которые разбор знает (ADR-012). Незнакомая — это новый
     * вид расхода, и превратить её в ноль строк значит занизить издержки
     * клиента, ничем себя не выдав. ADR-006 требует падать на дрейфе
     * схемы, а не молча его переживать.
     */
    private const array KNOWN_CATEGORIES = ['POSTING', 'ITEM', 'NON_ITEM'];

    /**
     * @return array{facts: list<MarketplaceExpenseFact>, lastId: string}
     */
    public function parse(
        string $rawBody,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        Uuid $rawDocumentId,
    ): array {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['accruals']) || !\is_array($decoded['accruals'])) {
            throw new \UnexpectedValueException('Ozon /v1/finance/accrual/by-day response must contain an "accruals" array.');
        }

        $facts = [];
        foreach ($decoded['accruals'] as $accrual) {
            if (!\is_array($accrual)) {
                throw new \UnexpectedValueException('Ozon accrual entry must be an object.');
            }

            foreach ($this->accrualToFacts($accrual, $companyId, $marketplaceAccountId, $rawDocumentId) as $fact) {
                $facts[] = $fact;
            }
        }

        $lastId = $decoded['last_id'] ?? '';
        if (!\is_string($lastId)) {
            throw new \UnexpectedValueException('Ozon accrual last_id must be a string.');
        }

        return ['facts' => $facts, 'lastId' => $lastId];
    }

    /**
     * @param array<array-key, mixed> $accrual
     *
     * @return list<MarketplaceExpenseFact>
     */
    private function accrualToFacts(
        array $accrual,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        Uuid $rawDocumentId,
    ): array {
        $accrualId = self::requireInt($accrual, 'accrual_id');
        $businessDate = new \DateTimeImmutable(self::requireString($accrual, 'date'));
        // Не у всех начислений он есть: у досрочной выплаты и части
        // общих расходов площадка не присылает его вовсе — относить
        // такой расход не к чему. Пустая строка, не NULL: колонка
        // участвует только в отображении, но пустое значение должно
        // быть одним, а не двумя.
        $unitNumber = self::optionalString($accrual, 'unit_number');

        $category = self::requireString($accrual, 'accrued_category');
        if (!\in_array($category, self::KNOWN_CATEGORIES, true)) {
            throw new \UnexpectedValueException("Начисление {$accrualId} категории '{$category}' — разбор такой не знает (ADR-012).");
        }

        // container_fees в снятой фикстуре не заполнен ни разу (ADR-012).
        // Появился — это четвёртая категория расхода внутри знакомой
        // тройки, и пропустить её так же нельзя.
        if (null !== ($accrual['container_fees'] ?? null)) {
            throw new \UnexpectedValueException("Начисление {$accrualId} содержит container_fees — блок, который разбор не знает (ADR-012).");
        }

        $rows = $this->rows($accrual, $accrualId);
        self::assertBalanced($rows, self::money($accrual['total_amount'] ?? null), $accrualId);
        self::assertUniqueKeys($rows, $accrualId);

        $facts = [];
        foreach ($rows as [$sku, $feeTypeId, $amount]) {
            $facts[] = MarketplaceExpenseFact::normalize(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                accrualId: $accrualId,
                businessDate: $businessDate,
                marketplaceSku: $sku,
                feeTypeId: $feeTypeId,
                unitNumber: $unitNumber,
                amount: $amount,
                rawDocumentId: $rawDocumentId,
            );
        }

        return $facts;
    }

    /**
     * @param array<array-key, mixed> $accrual
     *
     * @return list<array{0: string, 1: int, 2: Money}>
     */
    private function rows(array $accrual, int $accrualId): array
    {
        $rows = [];

        $posting = $accrual['posting'] ?? null;
        if (\is_array($posting)) {
            foreach (self::requireList($posting, 'products') as $product) {
                $sku = (string) self::requireInt($product, 'sku');

                $delivery = $product['delivery'] ?? null;
                if (\is_array($delivery)) {
                    foreach (self::requireList($delivery, 'services') as $service) {
                        $rows[] = [$sku, self::requireInt($service, 'type_id'), self::money($service['accrued'] ?? null)];
                    }
                }

                $commission = $product['commission'] ?? null;
                if (\is_array($commission)) {
                    foreach (self::saleRows($commission, $accrualId) as [$feeTypeId, $amount]) {
                        $rows[] = [$sku, $feeTypeId, $amount];
                    }
                }
            }
        }

        $itemFees = $accrual['item_fees'] ?? null;
        if (\is_array($itemFees)) {
            foreach (self::requireList($itemFees, 'fees') as $perSku) {
                $sku = (string) self::requireInt($perSku, 'sku');
                foreach (self::requireList($perSku, 'fees') as $fee) {
                    $rows[] = [$sku, self::requireInt($fee, 'type_id'), self::money($fee['accrued'] ?? null)];
                }
            }
        }

        $nonItemFee = $accrual['non_item_fee'] ?? null;
        if (\is_array($nonItemFee)) {
            // Артикула нет и быть не может: реклама и хранение относятся
            // к кабинету, а не к товару. Пустая строка, не NULL —
            // значение входит в склеенный ключ (ADR-012).
            $rows[] = ['', self::requireInt($nonItemFee, 'type_id'), self::money($nonItemFee['accrued'] ?? null)];
        }

        return $rows;
    }

    /**
     * Продажа или возврат: выручка и вознаграждение за продажу (ADR-036).
     * Знак — площадки: у возврата выручка отрицательная, вознаграждение
     * положительное.
     *
     * Штука — одна строка выручки: количества в ответе нет. Держится это
     * на равенстве `sale_amount` и `seller_price` (цена одной штуки);
     * разойдутся — строка на несколько штук, и посчитать её одной значило
     * бы ошибиться в количестве, ничем себя не выдав.
     *
     * @param array<array-key, mixed> $commission
     *
     * @return list<array{0: int, 1: Money}>
     */
    private static function saleRows(array $commission, int $accrualId): array
    {
        $revenue = self::money($commission['sale_amount'] ?? null);
        $sellerPrice = self::money($commission['seller_price'] ?? null);

        if (0 === $revenue->minorAmount()) {
            throw new \UnexpectedValueException("Начисление {$accrualId}: нулевая sale_amount — ни продажа, ни возврат (ADR-036).");
        }

        if ($revenue->currency() !== $sellerPrice->currency() || $revenue->minorAmount() !== $sellerPrice->minorAmount()) {
            throw new \UnexpectedValueException("Начисление {$accrualId}: sale_amount не равна seller_price — строка не на одну штуку (ADR-036).");
        }

        return [
            [OzonFeeTypeNames::REVENUE, $revenue],
            [OzonFeeTypeNames::SALE_COMMISSION, self::money($commission['sale_commission'] ?? null)],
        ];
    }

    /**
     * Пара «товар + тип» в начислении одна: ключ строки — accrual_id,
     * артикул и тип (ADR-012). Повтор — например, две записи products
     * одного артикула, то есть продажа нескольких штук, — дал бы одну
     * строку вместо двух. Отказ, а не сложение: сколько здесь штук,
     * по ответу не определить (ADR-036 п. 4).
     *
     * @param list<array{0: string, 1: int, 2: Money}> $rows
     */
    private static function assertUniqueKeys(array $rows, int $accrualId): void
    {
        $seen = [];
        foreach ($rows as [$sku, $feeTypeId]) {
            $key = $sku.'|'.$feeTypeId;
            if (isset($seen[$key])) {
                throw new \UnexpectedValueException("Начисление {$accrualId}: тип {$feeTypeId} по товару '{$sku}' встречается дважды — разбор не знает, как их различить (ADR-036).");
            }
            $seen[$key] = true;
        }
    }

    /**
     * @param list<array{0: string, 1: int, 2: Money}> $rows
     */
    private static function assertBalanced(array $rows, Money $total, int $accrualId): void
    {
        $parsed = [] === $rows
            ? Money::ofMinor(0, $total->currency())
            : Money::sum(array_map(static fn (array $row): Money => $row[2], $rows));

        if ($parsed->currency() !== $total->currency() || $parsed->minorAmount() !== $total->minorAmount()) {
            throw new \UnexpectedValueException(\sprintf('Начисление %d: строки разбора дают %d, а total_amount — %d (%s). Часть денег начисления разбор не понял (ADR-036).', $accrualId, $parsed->minorAmount(), $total->minorAmount(), $total->currency()));
        }
    }

    /**
     * Суммы приходят строкой с явной валютой — это подарок: приводить
     * float к минорным единицам не приходится вовсе, а валюта берётся
     * из самого поля, не константой коннектора.
     */
    private static function money(mixed $accrued): Money
    {
        if (!\is_array($accrued)) {
            throw new \UnexpectedValueException('Ozon accrual amount must be an object with amount and currency.');
        }

        $amount = self::requireString($accrued, 'amount');
        $currency = self::requireString($accrued, 'currency');

        if (1 !== preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)) {
            throw new \UnexpectedValueException("Ozon accrual amount '{$amount}' is not a decimal with at most two fraction digits.");
        }

        // Строкой, без float: число с плавающей точкой в денежных
        // вычислениях запрещено, включая промежуточные значения
        // (CLAUDE.md §3).
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $negative = str_starts_with($whole, '-');
        $minor = (int) ltrim($whole, '-') * 100 + (int) str_pad($fraction, 2, '0');

        return Money::ofMinor($negative ? -$minor : $minor, $currency);
    }

    /**
     * @param array<array-key, mixed> $source
     *
     * @return list<array<array-key, mixed>>
     */
    private static function requireList(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!\is_array($value)) {
            throw new \UnexpectedValueException("Ozon accrual field '{$key}' must be an array.");
        }

        $items = [];
        foreach ($value as $item) {
            if (!\is_array($item)) {
                throw new \UnexpectedValueException("Ozon accrual field '{$key}' must contain objects.");
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function requireInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException("Ozon accrual field '{$key}' must be an integer.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function optionalString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;

        return \is_string($value) ? $value : '';
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \UnexpectedValueException("Ozon accrual field '{$key}' must be a non-empty string.");
        }

        return $value;
    }
}
