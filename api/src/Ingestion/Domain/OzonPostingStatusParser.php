<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Чистый разбор одного snapshot /v2/posting/fbo/list в одно наблюдение
 * на posting независимо от количества products (ADR-019).
 */
final class OzonPostingStatusParser
{
    /**
     * @return list<MarketplacePostingStatus>
     */
    public function parse(
        string $rawBody,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        Uuid $rawDocumentId,
        \DateTimeImmutable $observedAt,
    ): array {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['result']) || !\is_array($decoded['result'])) {
            throw new \UnexpectedValueException('Ozon /v2/posting/fbo/list response must contain a "result" array.');
        }

        $statuses = [];
        foreach ($decoded['result'] as $posting) {
            if (!\is_array($posting)) {
                throw new \UnexpectedValueException('Ozon posting entry must be an object.');
            }

            $statuses[] = MarketplacePostingStatus::observe(
                companyId: $companyId,
                marketplaceAccountId: $marketplaceAccountId,
                postingNumber: self::requireString($posting, 'posting_number'),
                orderNumber: self::requireString($posting, 'order_number'),
                status: self::requireString($posting, 'status'),
                substatus: self::optionalString($posting, 'substatus'),
                cancelReasonId: self::optionalInt($posting, 'cancel_reason_id'),
                observedAt: $observedAt,
                rawDocumentId: $rawDocumentId,
            );
        }

        return $statuses;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \UnexpectedValueException("Expected field \"{$key}\" to be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || '' === $value) {
            throw new \UnexpectedValueException("Optional field \"{$key}\" must be null or a non-empty string.");
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function optionalInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_int($value)) {
            throw new \UnexpectedValueException("Optional field \"{$key}\" must be null or an integer.");
        }

        return $value;
    }
}
