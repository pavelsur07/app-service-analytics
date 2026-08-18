<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Infrastructure\Query;

use App\PriceMonitoring\Domain\TrackedSkuStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Отслеживаемые артикулы с последним наблюдением по каждому — левая
 * половина экрана СПП. Правую (цену кабинета на тот же момент) приносит
 * Ingestion через свой Facade, здесь её нет намеренно: таблицы чужого
 * модуля этот запрос не трогает (ADR-016).
 *
 * DBAL, без гидрации сущностей; build() отдаёт QueryBuilder (CLAUDE.md §5).
 *
 * Боковой подзапрос вместо группировки: нужна не агрегация, а одна
 * последняя строка на артикул, и `LIMIT 1` внутри `LATERAL` выражает
 * это прямо — в отличие от `DISTINCT ON`, который то же самое прячет
 * в неочевидный синтаксис.
 *
 * Артикул без наблюдений остаётся в выборке с пустыми полями: он
 * отслеживается, просто расширение до него ещё не дошло, и убрать его
 * с экрана значило бы соврать, что его не отслеживают.
 */
final readonly class TrackedSkuOverviewQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId, int $limit): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select(
                'tracked.marketplace_sku',
                'tracked.marketplace_account_id',
                'latest.displayed_price_minor',
                'latest.currency',
                'latest.observed_at',
            )
            ->from($this->fromWithLatestObservation())
            ->where('tracked.company_id = :companyId')
            ->andWhere('tracked.status = :status')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', TrackedSkuStatus::Active->value)
            ->orderBy('tracked.marketplace_sku', 'ASC')
            ->setMaxResults($limit);
    }

    /**
     * Соединение строкой, а не через leftJoin(): QueryBuilder не умеет
     * LATERAL, а он здесь и нужен — подзапрос ссылается на строку
     * внешней таблицы. Тот же приём, что у CompanySkusQuery с его UNION.
     */
    private function fromWithLatestObservation(): string
    {
        return <<<'SQL'
            tracked_sku tracked
            LEFT JOIN LATERAL (
                SELECT observation.displayed_price_minor,
                       observation.currency,
                       observation.observed_at
                FROM price_observation observation
                WHERE observation.company_id = tracked.company_id
                  AND observation.marketplace_sku = tracked.marketplace_sku
                ORDER BY observation.observed_at DESC
                LIMIT 1
            ) latest ON true
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): TrackedSkuOverviewRow
    {
        $sku = $row['marketplace_sku'];
        if (!\is_string($sku)) {
            throw new \UnexpectedValueException('Expected a string marketplace_sku.');
        }

        $observedAt = $row['observed_at'];
        $price = $row['displayed_price_minor'];
        $currency = $row['currency'];

        $accountId = $row['marketplace_account_id'];
        if (!\is_string($accountId)) {
            throw new \UnexpectedValueException('Expected a string marketplace_account_id.');
        }

        return new TrackedSkuOverviewRow(
            marketplaceSku: $sku,
            marketplaceAccountId: $accountId,
            displayedPriceMinor: null === $price ? null : self::intValue($price),
            currency: \is_string($currency) ? $currency : null,
            // Postgres отдаёт timestamp without time zone строкой;
            // приложение пишет эти колонки в UTC (date.timezone=UTC),
            // поэтому UTC объявляется явно — иначе браузер прочитал бы
            // момент как местное время.
            observedAt: \is_string($observedAt)
                ? new \DateTimeImmutable($observedAt, new \DateTimeZone('UTC'))
                : null,
        );
    }

    private static function intValue(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new \UnexpectedValueException('Expected an int-like money column.');
        }

        return (int) $value;
    }
}
