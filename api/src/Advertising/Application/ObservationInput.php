<?php

declare(strict_types=1);

namespace App\Advertising\Application;

use App\Shared\Domain\ValueObject\Money;
use Symfony\Component\Uid\Uuid;

final readonly class ObservationInput
{
    public function __construct(public string $companyId, public string $accountId, public string $policyId, public int $version, public string $day, public ?Money $spend, public ?Money $difference, public string $action, public string $reason, public bool $latched, public \DateTimeImmutable $at)
    {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body, \DateTimeImmutable $now): self
    {
        self::keys($body, ['schema_version', 'company_id', 'account_id', 'policy_id', 'runtime']);
        $runtime = $body['runtime'] ?? null;
        foreach (['company_id', 'account_id', 'policy_id'] as $key) {
            if (!\is_string($body[$key] ?? null) || !Uuid::isValid($body[$key])) {
                throw new \InvalidArgumentException('invalid_scope');
            }
        }
        if ('1' !== ($body['schema_version'] ?? null) || !\is_array($runtime) || !\is_array($runtime['decision'] ?? null) || !\is_int($runtime['applied_version'] ?? null) || $runtime['applied_version'] < 1 || !\is_string($runtime['day'] ?? null) || !\is_string($runtime['evaluated_at'] ?? null) || !\is_string($runtime['decision']['action'] ?? null) || !\in_array($runtime['decision']['action'], ['would_run', 'would_stop', 'blocked'], true) || !\is_string($runtime['decision']['reason'] ?? null) || 1 !== preg_match('/\A[a-z_]{1,64}\z/', $runtime['decision']['reason']) || false !== ($runtime['decision']['source_age_known'] ?? null) || !\is_bool($runtime['latched'] ?? null)) {
            throw new \InvalidArgumentException('invalid_observation');
        }
        self::keys($runtime, ['day', 'primary', 'sku', 'effective', 'latched', 'decision', 'error', 'applied_version', 'evaluated_at']);
        if (!\is_string($runtime['error']) || 1 !== preg_match('/\A[a-z_]{0,64}\z/', $runtime['error'])) {
            throw new \InvalidArgumentException('invalid_observation');
        }
        self::keys($runtime['decision'], ['action', 'reason', 'effective_spend', 'difference', 'reserve', 'source_age_known']);
        self::money($runtime['decision']['reserve']);
        self::money($runtime['decision']['effective_spend']);
        self::snapshot($runtime['primary']);
        if (null !== $runtime['sku']) {
            self::snapshot($runtime['sku']);
        }
        self::dateTime($runtime['evaluated_at']);
        $at = \DateTimeImmutable::createFromFormat(\DATE_RFC3339, $runtime['evaluated_at']);
        // Go may include nanoseconds, accepted only by the strict ISO parser below.
        if (false === $at && 1 === preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{1,9}(?:Z|[+-]\d{2}:\d{2})\z/', $runtime['evaluated_at'])) {
            $at = new \DateTimeImmutable($runtime['evaluated_at']);
        }
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $runtime['day']);
        if (false === $at || false === $day || $day->format('Y-m-d') !== $runtime['day'] || $at > $now->modify('+5 seconds') || $at < $now->modify('-1 day') || $at->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d') !== $runtime['day']) {
            throw new \InvalidArgumentException('invalid_observation_time');
        }

        foreach (['company_id', 'account_id', 'policy_id'] as $key) {
            if (!\is_string($body[$key] ?? null)) {
                throw new \InvalidArgumentException('invalid_scope');
            }
        }
        \assert(\is_string($body['company_id']) && \is_string($body['account_id']) && \is_string($body['policy_id']));

        return new self($body['company_id'], $body['account_id'], $body['policy_id'], $runtime['applied_version'], $runtime['day'], self::money($runtime['effective'] ?? null), self::money($runtime['decision']['difference'] ?? null), $runtime['decision']['action'], $runtime['decision']['reason'], $runtime['latched'], $at);
    }

    private static function money(mixed $raw): ?Money
    {
        if (null === $raw) {
            return null;
        }
        if (!\is_array($raw) || !\is_int($raw['amount_minor'] ?? null) || $raw['amount_minor'] < 0 || $raw['amount_minor'] > 9007199254740991 || 'RUB' !== ($raw['currency'] ?? null)) {
            throw new \InvalidArgumentException('invalid_money');
        }

        self::keys($raw, ['amount_minor', 'currency']);

        return Money::ofMinor($raw['amount_minor'], $raw['currency']);
    }

    /**
     * @param array<array-key,mixed> $body
     * @param list<string>           $expected
     */
    private static function keys(array $body, array $expected): void
    {
        if (\count($body) !== \count($expected) || [] !== array_diff($expected, array_keys($body))) {
            throw new \InvalidArgumentException('contract_violation');
        }
    }

    private static function snapshot(mixed $value): void
    {
        if (!\is_array($value)) {
            throw new \InvalidArgumentException('invalid_snapshot');
        }
        self::keys($value, ['campaign_id', 'day', 'spend', 'fetched_at', 'source', 'complete']);
        if (!\is_string($value['campaign_id']) || !\is_string($value['day']) || !\is_string($value['fetched_at']) || !\is_bool($value['complete']) || !\in_array($value['source'], ['aggregate', 'sku', ''], true)) {
            throw new \InvalidArgumentException('invalid_snapshot');
        }
        self::dateTime($value['fetched_at']);
        if (!\is_array($value['spend'])) {
            throw new \InvalidArgumentException('invalid_snapshot');
        }
        if (false === $value['complete'] && '' === $value['source']) {
            if (2 !== \count($value['spend']) || 0 !== ($value['spend']['amount_minor'] ?? null) || '' !== ($value['spend']['currency'] ?? null)) {
                throw new \InvalidArgumentException('invalid_snapshot');
            }
        } else {
            self::money($value['spend']);
        }
    }

    private static function dateTime(string $raw): void
    {
        if (1 !== preg_match('/\A\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[Zz]|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $raw)) {
            throw new \InvalidArgumentException('invalid_snapshot_time');
        }
        try {
            new \DateTimeImmutable($raw);
        } catch (\Exception) {
            throw new \InvalidArgumentException('invalid_snapshot_time');
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if (false !== $errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new \InvalidArgumentException('invalid_snapshot_time');
        }
    }
}
