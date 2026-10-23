<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DemandMatrix;

/**
 * Позиция keyset-страницы для порядка orders28 DESC, marketplace_sku ASC.
 * Привязана к дню отчёта и поиску: курсор другого дня или другого поиска
 * продолжал бы порядок другого набора строк.
 */
final readonly class DemandMatrixCursor
{
    public function __construct(
        public string $today,
        public string $search,
        public int $orders28,
        public string $marketplaceSku,
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
            !\is_array($parts) || 4 !== \count($parts)
            || !\is_string($parts[0] ?? null) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0])
            || !\is_string($parts[1] ?? null)
            || !\is_int($parts[2] ?? null)
            || !\is_string($parts[3] ?? null) || '' === $parts[3]
        ) {
            return null;
        }

        return new self($parts[0], $parts[1], $parts[2], $parts[3]);
    }

    public function encode(): string
    {
        return base64_encode(json_encode(
            [$this->today, $this->search, $this->orders28, $this->marketplaceSku],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE,
        ));
    }
}
