<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\StockPlacement;

/**
 * Позиция keyset-страницы для порядка priority DESC, recommended DESC,
 * marketplace_sku ASC, cluster ASC. Привязана к параметрам отчёта целиком
 * (день, целевое покрытие, срок поставки, фильтр статуса): курсор другого
 * расчёта продолжал бы порядок другого набора строк.
 */
final readonly class StockPlacementCursor
{
    public function __construct(
        public string $today,
        public int $targetDays,
        public int $leadDays,
        public ?string $status,
        public int $priority,
        public int $recommended,
        public string $marketplaceSku,
        public string $cluster,
    ) {
    }

    public static function decode(string $cursor): ?self
    {
        $json = base64_decode($cursor, true);
        if (false === $json || '' === $json) {
            return null;
        }
        try {
            $parts = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (
            !\is_array($parts) || 8 !== \count($parts)
            || !\is_string($parts[0] ?? null) || !\is_int($parts[1] ?? null) || !\is_int($parts[2] ?? null)
            || !(null === ($parts[3] ?? null) || \is_string($parts[3]))
            || !\is_int($parts[4] ?? null) || !\is_int($parts[5] ?? null)
            || !\is_string($parts[6] ?? null) || '' === $parts[6]
            || !\is_string($parts[7] ?? null) || '' === $parts[7]
            || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0])
        ) {
            return null;
        }

        $status = $parts[3] ?? null;

        return new self($parts[0], $parts[1], $parts[2], \is_string($status) ? $status : null, $parts[4], $parts[5], $parts[6], $parts[7]);
    }

    public function encode(): string
    {
        return base64_encode(json_encode(
            [$this->today, $this->targetDays, $this->leadDays, $this->status, $this->priority, $this->recommended, $this->marketplaceSku, $this->cluster],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE,
        ));
    }
}
