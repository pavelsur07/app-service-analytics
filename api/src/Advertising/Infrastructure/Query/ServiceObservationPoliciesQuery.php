<?php

declare(strict_types=1);

namespace App\Advertising\Infrastructure\Query;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/** Only the exact internal controller may use this cross-company query (ADR-052). */
final readonly class ServiceObservationPoliciesQuery
{
    public function __construct(private Connection $db)
    {
    }

    public function page(?string $afterId, int $limit): QueryBuilder
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('invalid_limit');
        }
        $query = $this->db->createQueryBuilder()->select('id', 'company_id', 'account_id', 'campaign_id', 'version', 'enabled', 'daily_limit_minor', 'currency', 'start_time', 'stop_time', 'poll_seconds')->from('advertising_observation_policy')->orderBy('id')->setMaxResults($limit + 1);
        if (null !== $afterId) {
            $query->where('id>:after')->setParameter('after', $afterId);
        }

        return $query;
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

    /** @param array<string,mixed> $row */
    public static function mapRow(array $row): ServiceObservationPolicyRow
    {
        $row = self::scalars($row);

        return new ServiceObservationPolicyRow((string) $row['id'], (string) $row['company_id'], (string) $row['account_id'], (string) $row['campaign_id'], (int) $row['version'], (bool) $row['enabled'], Money::ofMinor((int) $row['daily_limit_minor'], (string) $row['currency']), (string) $row['start_time'], (string) $row['stop_time'], (int) $row['poll_seconds']);
    }
}
