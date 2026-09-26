<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\DeliverySpeed;

/**
 * Позиция keyset-страницы «SKU × кластер доставки» для порядка
 * lost_hours DESC, marketplace_sku ASC, cluster_to ASC. Привязана к окну
 * целиком — числу дней и последнему дню, как в «Локализации».
 */
final readonly class DeliverySpeedSkuCursor
{
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        public int $days,
        public \DateTimeImmutable $to,
        public int $lostHours,
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
            || 5 !== \count($parts)
            || !\is_int($parts[0] ?? null) || !\is_string($parts[1] ?? null) || !\is_int($parts[2] ?? null)
            || !\is_string($parts[3] ?? null) || !\is_string($parts[4] ?? null)
            || $parts[2] < 0 || '' === $parts[3] || '' === $parts[4]
        ) {
            return null;
        }
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $parts[1], new \DateTimeZone(self::TIMEZONE));
        if (false === $to || $to->format('Y-m-d') !== $parts[1]) {
            return null;
        }

        return new self($parts[0], $to, $parts[2], $parts[3], $parts[4]);
    }

    public function encode(): string
    {
        return base64_encode(json_encode(
            [$this->days, $this->to->format('Y-m-d'), $this->lostHours, $this->marketplaceSku, $this->clusterTo],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE,
        ));
    }
}
