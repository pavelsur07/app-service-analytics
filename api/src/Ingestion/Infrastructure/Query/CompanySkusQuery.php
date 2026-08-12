<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Артикулы товаров компании — DBAL, без гидрации SalesFact (CLAUDE.md §5).
 * build() отдаёт QueryBuilder (§5), выполнение и сборку делает контроллер.
 *
 * Зачем этот список расширению: чтобы решать «моя ли это карточка»
 * локально, у себя, и не спрашивать сервер про каждый открытый товар.
 * Иначе мы бы узнавали, какие чужие карточки смотрит клиент, — а список
 * отслеживаемых конкурентов ценнее самих цен и нам не принадлежит.
 *
 * Курсор, не offset: число артикулов растёт с каталогом клиента
 * (docs/patterns.md, «Пагинация»). Курсор — сам артикул, без base64
 * и JSON: колонка сортировки одна, кодировать нечего. Подделать его
 * можно только в границах своей же компании — companyId в запросе
 * остаётся.
 *
 * Марктплейс не фильтруется и в пути не назван: сегодня у компании
 * только Ozon, а `marketplace` живёт в таблице чужого модуля (Identity).
 * Со вторым маркетплейсом здесь появится фильтр — и тогда же он появится
 * в контракте, а не раньше.
 */
final readonly class CompanySkusQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId, ?string $cursor, int $limit): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->select('marketplace_sku')
            ->distinct()
            ->from('sales_fact')
            ->where('company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('marketplace_sku', 'ASC')
            // +1 — узнать, есть ли следующая страница, без COUNT(*)
            // на факт-таблице (CLAUDE.md §5).
            ->setMaxResults($limit + 1);

        if (null !== $cursor) {
            $qb->andWhere('marketplace_sku > :cursor')->setParameter('cursor', $cursor);
        }

        return $qb;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): CompanySkuRow
    {
        $value = $row['marketplace_sku'];
        if (!\is_string($value) && !\is_int($value)) {
            throw new \UnexpectedValueException('Expected a string-like marketplace_sku.');
        }

        return new CompanySkuRow((string) $value);
    }
}
