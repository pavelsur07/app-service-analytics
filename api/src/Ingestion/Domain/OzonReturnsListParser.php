<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Строгий разбор /v1/returns/list в outcome facts (ADR-019).
 * Неизвестная форма ответа останавливает ingestion, а не занижает метрику.
 */
final class OzonReturnsListParser
{
    public function parse(
        string $rawBody,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        Uuid $rawDocumentId,
        int $previousLastId,
    ): OzonReturnsPage {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['returns']) || !\is_array($decoded['returns'])) {
            throw new \UnexpectedValueException('Ozon /v1/returns/list response must contain a "returns" array.');
        }

        $hasNext = $decoded['has_next'] ?? null;
        if (!\is_bool($hasNext)) {
            throw new \UnexpectedValueException('Ozon returns has_next must be a boolean.');
        }

        $facts = [];
        $lastReturnId = null;
        foreach ($decoded['returns'] as $return) {
            if (!\is_array($return)) {
                throw new \UnexpectedValueException('Ozon return entry must be an object.');
            }

            $lastReturnId = self::requireInt($return, 'id');
            $product = self::requireObject($return, 'product');
            $visual = self::requireObject($return, 'visual');
            $visualStatus = self::requireObject($visual, 'status');

            try {
                $visualStatusChangedAt = new \DateTimeImmutable(self::requireString($visual, 'change_moment'));
            } catch (\Exception $exception) {
                throw new \UnexpectedValueException('Ozon return visual.change_moment must be a valid timestamp.', previous: $exception);
            }

            $facts[] = MarketplaceReturnFact::normalize(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                sourceRowId: (string) $lastReturnId,
                orderNumber: self::requireString($return, 'order_number'),
                marketplaceSku: (string) self::requireInt($product, 'sku'),
                returnType: self::requireString($return, 'type'),
                returnReasonName: self::requireString($return, 'return_reason_name'),
                postingNumber: self::requireString($return, 'posting_number'),
                sourceId: self::requireInt($return, 'source_id'),
                quantity: self::requireInt($product, 'quantity'),
                visualStatusId: self::requireInt($visualStatus, 'id'),
                visualStatus: self::requireString($visualStatus, 'sys_name'),
                visualStatusChangedAt: $visualStatusChangedAt,
                rawDocumentId: $rawDocumentId,
            );
        }

        if ($hasNext && null === $lastReturnId) {
            throw new \UnexpectedValueException('Ozon returns has_next=true requires a non-empty page and cursor.');
        }
        if ($hasNext && $lastReturnId === $previousLastId) {
            throw new \UnexpectedValueException('Ozon returns cursor did not change.');
        }

        return new OzonReturnsPage(
            facts: $facts,
            hasNext: $hasNext,
            lastId: $hasNext ? $lastReturnId : null,
        );
    }

    /**
     * @param array<array-key, mixed> $source
     *
     * @return array<array-key, mixed>
     */
    private static function requireObject(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!\is_array($value)) {
            throw new \UnexpectedValueException("Ozon return field '{$key}' must be an object.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function requireInt(array $source, string $key): int
    {
        $value = $source[$key] ?? null;
        if (!\is_int($value)) {
            throw new \UnexpectedValueException("Ozon return field '{$key}' must be an integer.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function requireString(array $source, string $key): string
    {
        $value = $source[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \UnexpectedValueException("Ozon return field '{$key}' must be a non-empty string.");
        }

        return $value;
    }
}
