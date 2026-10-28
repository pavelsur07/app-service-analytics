<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

/** A diagnostic position in one proven RR context, never a model accumulator. */
final readonly class NetTrainingCursor
{
    private function __construct(
        public string $contextFingerprint,
        public string $skuFingerprint,
        public NetTrainingPopulation $population,
        public string $dateKey,
        public bool $orderMissing,
        public string $orderKey,
        public string $marketplaceSku,
    ) {
        $format = 10 === \strlen($dateKey) ? 'Y-m-d' : 'Y-m-d H:i:s';
        $date = \DateTimeImmutable::createFromFormat('!'.$format, $dateKey, new \DateTimeZone('UTC'));
        if (1 !== preg_match('/^[a-f0-9]{64}$/D', $contextFingerprint) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $skuFingerprint)
            || false === $date || $date->format($format) !== $dateKey || '' === $orderKey || \strlen($orderKey) > 3000
            || '' === $marketplaceSku || mb_strlen($marketplaceSku) > 64 || 1 !== preg_match('//u', $orderKey.$marketplaceSku)
            || 1 === preg_match('/[\x00-\x1F\x7F]/u', $orderKey.$marketplaceSku)) {
            throw new \InvalidArgumentException('Invalid training cursor.');
        }
    }

    public static function afterRow(NetTrainingContext $context, NetTrainingRequest $request, NetTrainingPopulation $population, TrainingGroupDiagnosticRow $row): self
    {
        if (null === $row->dateKey || null === $row->orderKey || null === $row->marketplaceSku || !$row->inputComplete) {
            throw new \InvalidArgumentException('Unavailable diagnostics cannot become a cursor.');
        }

        return new self($context->fingerprint(), self::skus($request), $population, $row->dateKey, $row->orderMissing, $row->orderKey, $row->marketplaceSku);
    }

    public function assertContext(NetTrainingContext $context, NetTrainingRequest $request, NetTrainingPopulation $population): void
    {
        if ($context->fingerprint() !== $this->contextFingerprint || self::skus($request) !== $this->skuFingerprint || $population !== $this->population) {
            throw new \InvalidArgumentException('Training cursor does not belong to this scope or snapshot.');
        }
    }

    public function toString(): string
    {
        return rtrim(strtr(base64_encode(json_encode([$this->contextFingerprint, $this->skuFingerprint, $this->population->value, $this->dateKey, $this->orderMissing, $this->orderKey, $this->marketplaceSku], \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public static function fromString(string $value): self
    {
        if (\strlen($value) > 8192 || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new \InvalidArgumentException('Invalid training cursor encoding.');
        }
        $raw = base64_decode(strtr($value, '-_', '+/'), true);
        try {
            $parts = false === $raw ? null : json_decode($raw, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $parts = null;
        }
        if (!\is_array($parts) || !array_is_list($parts) || 7 !== \count($parts)
            || !\is_string($parts[0]) || !\is_string($parts[1]) || !\is_string($parts[2]) || !\is_string($parts[3])
            || !\is_bool($parts[4]) || !\is_string($parts[5]) || !\is_string($parts[6]) || null === ($population = NetTrainingPopulation::tryFrom($parts[2]))) {
            throw new \InvalidArgumentException('Invalid training cursor fields.');
        }

        return new self($parts[0], $parts[1], $population, $parts[3], $parts[4], $parts[5], $parts[6]);
    }

    private static function skus(NetTrainingRequest $request): string
    {
        return hash('sha256', json_encode($request->marketplaceSkus, \JSON_THROW_ON_ERROR));
    }
}
