<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Infrastructure\Query;

use App\PriceMonitoring\Domain\TrackedSkuStatus;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Артикулы, за которыми компания следит сейчас — DBAL, без гидрации
 * сущностей (CLAUDE.md §5). build() отдаёт QueryBuilder (§5), выполнение
 * и сборку делает контроллер.
 *
 * Только `active`: остановленные строки остаются в таблице ради истории
 * наблюдений, но обходить их расширению незачем.
 *
 * Курсор, не offset, и курсор — сам артикул: та же форма, что
 * у `CompanySkusQuery`, и расширение уже умеет её листать
 * (`apps/extension/src/shared/catalog.ts`). Подделать курсор можно
 * только в границах своей компании — companyId остаётся в запросе.
 * Список у компании невелик (потолок в `StartTrackingAction`), но
 * пагинация здесь не ради размера, а потому что списка без предела
 * не отдают вовсе (§5).
 */
final readonly class TrackedSkusQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId, ?string $cursor, int $limit): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('marketplace_sku')
            ->from('tracked_sku')
            ->where('company_id = :companyId')
            ->andWhere('status = :status')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', TrackedSkuStatus::Active->value)
            ->orderBy('marketplace_sku', 'ASC')
            // +1 — узнать, есть ли следующая страница, не считая строки.
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $qb->andWhere('marketplace_sku > :cursor')->setParameter('cursor', $cursor);
        }

        return $qb;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): TrackedSkuRow
    {
        $value = $row['marketplace_sku'];
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string marketplace_sku.');
        }

        return new TrackedSkuRow($value);
    }
}
