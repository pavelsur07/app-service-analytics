<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Localization;

/**
 * Позиция keyset-страницы «SKU × кластер доставки» для порядка
 * nonlocal_quantity DESC, marketplace_sku ASC, cluster_to ASC.
 * Привязана к периоду: курсор 30-дневного отчёта в 90-дневном — ошибка.
 */
final readonly class LocalizationSkuCursor
{
    public function __construct(
        public int $days,
        public int $nonlocalQuantity,
        public string $marketplaceSku,
        public string $clusterTo,
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
            !\is_array($parts)
            || 4 !== \count($parts)
            || !\is_int($parts[0] ?? null) || !\is_int($parts[1] ?? null)
            || !\is_string($parts[2] ?? null) || !\is_string($parts[3] ?? null)
            || $parts[1] < 0 || '' === $parts[2] || '' === $parts[3]
        ) {
            return null;
        }

        return new self($parts[0], $parts[1], $parts[2], $parts[3]);
    }

    public function encode(): string
    {
        return base64_encode(json_encode(
            [$this->days, $this->nonlocalQuantity, $this->marketplaceSku, $this->clusterTo],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE,
        ));
    }
}
