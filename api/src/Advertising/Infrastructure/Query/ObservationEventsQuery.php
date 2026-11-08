<?php

declare(strict_types=1);

namespace App\Advertising\Infrastructure\Query;

use App\Advertising\Application\ObservationEventResponse;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class ObservationEventsQuery
{
    public function __construct(private Connection $db)
    {
    }

    public function list(string $companyId, ?string $afterId, int $limit, ?string $accountId): QueryBuilder
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('invalid_limit');
        }
        $query = $this->db->createQueryBuilder()->select('id', 'policy_id', 'account_id', 'campaign_id', 'version', 'kind', 'actor_id', 'action', 'reason', 'details', 'occurred_at', 'limit_minor', 'spend_minor', 'difference_minor', 'currency')
            ->from('advertising_observation_event')->where('company_id=:company')->setParameter('company', $companyId)->orderBy('id', 'DESC')->setMaxResults($limit);
        if (null !== $afterId) {
            $query->andWhere('id<:after')->setParameter('after', $afterId);
        }
        if (null !== $accountId) {
            $query->andWhere('account_id=:account')->setParameter('account', $accountId);
        }

        return $query;
    }

    /** @param array<string, mixed> $row */
    public static function map(array $row): ObservationEventResponse
    {
        foreach (['id', 'policy_id', 'account_id', 'campaign_id', 'kind', 'action', 'reason', 'details', 'occurred_at'] as $key) {
            if (!\is_string($row[$key] ?? null)) {
                throw new \UnexpectedValueException('invalid_event_row');
            }
        }
        $version = $row['version'] ?? null;
        $actor = $row['actor_id'] ?? null;
        if ((!\is_int($version) && !\is_string($version)) || (null !== $actor && !\is_string($actor))) {
            throw new \UnexpectedValueException('invalid_event_row');
        }
        $details = json_decode($row['details'], true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($details) || array_is_list($details)) {
            throw new \UnexpectedValueException('invalid_event_details');
        }
        $scalars = [];
        foreach ($details as $key => $value) {
            if (!\is_string($key) || (null !== $value && !\is_int($value) && !\is_string($value) && !\is_bool($value))) {
                throw new \UnexpectedValueException('invalid_event_details');
            }
            $scalars[$key] = $value;
        }

        $money = static function (string $column) use ($row): ?array {
            $amount = $row[$column] ?? null;
            if (null === $amount) {
                return null;
            }
            if ((!\is_int($amount) && !\is_string($amount)) || !\is_string($row['currency'] ?? null)) {
                throw new \UnexpectedValueException('invalid_event_money');
            }
            $value = Money::ofMinor((int) $amount, $row['currency']);

            return ['amountMinor' => $value->minorAmount(), 'currency' => $value->currency()];
        };

        return new ObservationEventResponse($row['id'], $row['policy_id'], $row['account_id'], $row['campaign_id'], (int) $version, $row['kind'], $actor, $row['action'], $row['reason'], $scalars, new \DateTimeImmutable($row['occurred_at'])->format(\DATE_RFC3339), $money('limit_minor'), $money('spend_minor'), $money('difference_minor'));
    }
}
