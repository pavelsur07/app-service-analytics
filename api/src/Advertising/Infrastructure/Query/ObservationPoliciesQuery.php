<?php

declare(strict_types=1);

namespace App\Advertising\Infrastructure\Query;

use App\Advertising\Application\ObservationPolicyResponse;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class ObservationPoliciesQuery
{
    public function __construct(private Connection $db)
    {
    }

    public function list(string $companyId, ?string $afterId, int $limit): QueryBuilder
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('invalid_limit');
        }
        $query = $this->db->createQueryBuilder()->select('p.id', 'p.account_id', 'p.campaign_id', 'p.version', 'p.enabled', 'p.daily_limit_minor', 'p.currency', 'p.start_time', 'p.stop_time', 'p.poll_seconds', 's.applied_version', 's.day', 's.spend_minor', 's.difference_minor', 's.action', 's.reason', 's.latched', 's.evaluated_at')
            ->from('advertising_observation_policy', 'p')->leftJoin('p', 'advertising_observation_state', 's', 's.company_id=p.company_id AND s.policy_id=p.id AND s.account_id=p.account_id')
            ->where('p.company_id=:company')->setParameter('company', $companyId)->orderBy('p.id')->setMaxResults($limit);
        if (null !== $afterId) {
            $query->andWhere('p.id>:after')->setParameter('after', $afterId);
        }

        return $query;
    }

    public function one(string $companyId, string $policyId): QueryBuilder
    {
        return $this->list($companyId, null, 1)->andWhere('p.id=:policy')->setParameter('policy', $policyId);
    }

    /** @param array<string, mixed> $row */
    public static function map(array $row): ObservationPolicyResponse
    {
        $row = self::scalars($row);
        $currency = (string) $row['currency'];
        $money = static function (int|string|bool|null $amount) use ($currency): ?array {
            if (null === $amount) {
                return null;
            }
            $value = Money::ofMinor((int) $amount, $currency);

            return ['amountMinor' => $value->minorAmount(), 'currency' => $value->currency()];
        };
        $observation = null;
        if (null !== $row['applied_version']) {
            $observation = ['appliedVersion' => (int) $row['applied_version'], 'day' => (string) $row['day'], 'spend' => $money($row['spend_minor']), 'difference' => $money($row['difference_minor']), 'action' => (string) $row['action'], 'reason' => (string) $row['reason'], 'latched' => (bool) $row['latched'], 'evaluatedAt' => new \DateTimeImmutable((string) $row['evaluated_at'])->format(\DATE_RFC3339)];
        }

        return new ObservationPolicyResponse((string) $row['id'], (string) $row['account_id'], (string) $row['campaign_id'], (int) $row['version'], 'observe', (bool) $row['enabled'], $money($row['daily_limit_minor']) ?? throw new \LogicException('missing_limit'), (string) $row['start_time'], (string) $row['stop_time'], 'Europe/Moscow', (int) $row['poll_seconds'], null !== $observation && $observation['appliedVersion'] === (int) $row['version'] ? 'applied' : 'saved', $observation);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, int|string|bool|null>
     */
    private static function scalars(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (null !== $value && !\is_int($value) && !\is_string($value) && !\is_bool($value)) {
                throw new \UnexpectedValueException('invalid_database_row');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
