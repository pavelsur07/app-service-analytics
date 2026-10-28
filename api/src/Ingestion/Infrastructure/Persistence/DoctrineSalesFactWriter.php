<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\FactCaptureOrigin;
use App\Ingestion\Domain\SalesFact;
use App\Ingestion\Domain\SalesFactRepository;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * DBAL, не ORM (CLAUDE.md §6: факт-таблицы ORM никогда не пишет).
 * INSERT ... ON CONFLICT DO UPDATE по естественному ключу, апдейт —
 * только если row_hash реально изменился: WHERE ... IS DISTINCT FROM
 * не даёт ON CONFLICT перезаписать first_loaded_at при неизменном
 * контенте (в SET его нет вовсе — колонка не участвует в обновлении).
 *
 * Один SQL-запрос на чанк, не один на строку (CLAUDE.md: «запросов
 * в цикле нет») — VALUES с несколькими кортежами в одном INSERT.
 */
final readonly class DoctrineSalesFactWriter implements SalesFactRepository
{
    private const int CHUNK_SIZE = 500;

    /**
     * Атрибуты заказа, которые бэкфилл восстанавливает из исторического raw.
     * Номера отправления и заказа — ссылки, неизменны: только заполняются.
     */
    private const array BACKFILL_LINK_COLUMNS = ['posting_number', 'order_number'];

    /**
     * Атрибуты, которые у одного отправления могут различаться между
     * снимками (переназначение склада, дозаполненный город). Непустое
     * значение из того raw, из которого получена текущая версия строки
     * (raw_document_id, прослеживаемость ADR-006), побеждает: оно согласовано
     * с её статусом и суммами. Пустое в нём ничего не стирает, а любой
     * другой снимок только заполняет пустое. Когда значение в raw текущей
     * версии есть, итог не зависит от порядка обхода. Когда его там нет,
     * остаётся первое значение, попавшее в строку: уже записанное до
     * прогона либо, если поле было пустым, из самого раннего снимка окна
     * --from/--to, где оно есть (обход по возрастанию received_at), — даже
     * если позднейшие снимки с ним расходятся.
     */
    private const array BACKFILL_SNAPSHOT_COLUMNS = [
        'warehouse_id',
        'warehouse_name',
        'delivery_city',
        'cluster_from',
        'cluster_to',
    ];

    /**
     * Неизменные атрибуты вне row_hash (момент заказа): только заполняются
     * и в сверку снимка для перевода хэша не входят.
     */
    private const array BACKFILL_FILL_ONLY_COLUMNS = ['ordered_at'];

    private const string SAME_RAW = 'sales_fact.raw_document_id = EXCLUDED.raw_document_id';

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock = new NativeClock(),
    ) {
    }

    public function upsertAll(array $facts, FactCaptureOrigin $origin = FactCaptureOrigin::Unknown): void
    {
        foreach (array_chunk($facts, self::CHUNK_SIZE) as $chunk) {
            $this->upsertChunk($chunk, $origin);
        }
    }

    public function backfillLinks(string $companyId, array $facts): void
    {
        foreach (array_chunk($facts, self::CHUNK_SIZE) as $chunk) {
            $this->backfillLinksChunk($companyId, $chunk);
        }
    }

    /**
     * @param list<SalesFact> $facts
     */
    private function backfillLinksChunk(string $companyId, array $facts): void
    {
        if ([] === $facts) {
            return;
        }

        $valuesSql = [];
        $params = [];
        foreach ($facts as $i => $fact) {
            if ($fact->companyId()->toRfc4122() !== $companyId) {
                throw new \InvalidArgumentException('Sales fact belongs to another company.');
            }

            $valuesSql[] = "(:companyId{$i}, :marketplaceAccountId{$i}, :sourceRowId{$i}, :businessDate{$i}, "
                .":status{$i}, :marketplaceSku{$i}, :quantity{$i}, :amountMinor{$i}, :commissionAmountMinor{$i}, "
                .":currency{$i}, :rawDocumentId{$i}, :rowHash{$i}, :firstLoadedAt{$i}, :lastUpdatedAt{$i}, "
                .":postingNumber{$i}, :orderNumber{$i}, :warehouseId{$i}, :warehouseName{$i}, :deliveryCity{$i}, "
                .":clusterFrom{$i}, :clusterTo{$i}, :orderedAt{$i}, 1, false)";

            $params["companyId{$i}"] = $companyId;
            $params["marketplaceAccountId{$i}"] = $fact->marketplaceAccountId()->toRfc4122();
            $params["sourceRowId{$i}"] = $fact->sourceRowId();
            $params["businessDate{$i}"] = $fact->businessDate()->format('Y-m-d');
            $params["status{$i}"] = $fact->status();
            $params["marketplaceSku{$i}"] = $fact->marketplaceSku();
            $params["quantity{$i}"] = $fact->quantity();
            $params["amountMinor{$i}"] = $fact->amount()->minorAmount();
            $params["commissionAmountMinor{$i}"] = $fact->commissionAmount()->minorAmount();
            $params["currency{$i}"] = $fact->amount()->currency();
            $params["rawDocumentId{$i}"] = $fact->rawDocumentId()->toRfc4122();
            $params["rowHash{$i}"] = $fact->rowHash();
            $params["firstLoadedAt{$i}"] = $fact->firstLoadedAt()->format('Y-m-d H:i:sP');
            $params["lastUpdatedAt{$i}"] = $fact->lastUpdatedAt()->format('Y-m-d H:i:sP');
            $params["postingNumber{$i}"] = $fact->postingNumber();
            $params["orderNumber{$i}"] = $fact->orderNumber();
            $params["warehouseId{$i}"] = $fact->warehouseId();
            $params["warehouseName{$i}"] = $fact->warehouseName();
            $params["deliveryCity{$i}"] = $fact->deliveryCity();
            $params["clusterFrom{$i}"] = $fact->clusterFrom();
            $params["clusterTo{$i}"] = $fact->clusterTo();
            $params["orderedAt{$i}"] = $fact->orderedAt()?->format('Y-m-d H:i:s');
        }

        [$set, $where] = self::backfillSetAndWhere();
        $sql = <<<SQL
            INSERT INTO sales_fact
                (company_id, marketplace_account_id, source_row_id, business_date, status, marketplace_sku,
                 quantity, amount_minor, commission_amount_minor, currency, raw_document_id, row_hash,
                 first_loaded_at, last_updated_at, posting_number, order_number,
                 warehouse_id, warehouse_name, delivery_city, cluster_from, cluster_to, ordered_at, fact_revision, capture_initial)
            VALUES {$this->joinValues($valuesSql)}
            ON CONFLICT (company_id, marketplace_account_id, source_row_id)
            DO UPDATE SET {$set}, fact_revision = sales_fact.fact_revision + 1, capture_initial = false
            WHERE {$where}
            SQL;

        $this->connection->executeStatement($this->captureSql($sql, $facts, $params, FactCaptureOrigin::HistoricalBackfill, 'backfill_links'), $params);
    }

    /**
     * @param list<SalesFact> $facts
     */
    private function upsertChunk(array $facts, FactCaptureOrigin $origin): void
    {
        if ([] === $facts) {
            return;
        }

        $initial = FactCaptureOrigin::LivePolling === $origin ? 'true' : 'false';
        $valuesSql = [];
        $params = [];
        foreach ($facts as $i => $fact) {
            $valuesSql[] = "(:companyId{$i}, :marketplaceAccountId{$i}, :sourceRowId{$i}, :businessDate{$i}, "
                .":status{$i}, :marketplaceSku{$i}, :quantity{$i}, :amountMinor{$i}, :commissionAmountMinor{$i}, "
                .":currency{$i}, :rawDocumentId{$i}, :rowHash{$i}, :firstLoadedAt{$i}, :lastUpdatedAt{$i}, "
                .":postingNumber{$i}, :orderNumber{$i}, :warehouseId{$i}, :warehouseName{$i}, :deliveryCity{$i}, "
                .":clusterFrom{$i}, :clusterTo{$i}, :orderedAt{$i}, 1, {$initial})";

            $params["companyId{$i}"] = $fact->companyId()->toRfc4122();
            $params["marketplaceAccountId{$i}"] = $fact->marketplaceAccountId()->toRfc4122();
            $params["sourceRowId{$i}"] = $fact->sourceRowId();
            $params["businessDate{$i}"] = $fact->businessDate()->format('Y-m-d');
            $params["status{$i}"] = $fact->status();
            $params["marketplaceSku{$i}"] = $fact->marketplaceSku();
            $params["quantity{$i}"] = $fact->quantity();
            $params["amountMinor{$i}"] = $fact->amount()->minorAmount();
            $params["commissionAmountMinor{$i}"] = $fact->commissionAmount()->minorAmount();
            $params["currency{$i}"] = $fact->amount()->currency();
            $params["rawDocumentId{$i}"] = $fact->rawDocumentId()->toRfc4122();
            $params["rowHash{$i}"] = $fact->rowHash();
            $params["firstLoadedAt{$i}"] = $fact->firstLoadedAt()->format('Y-m-d H:i:sP');
            $params["lastUpdatedAt{$i}"] = $fact->lastUpdatedAt()->format('Y-m-d H:i:sP');
            $params["postingNumber{$i}"] = $fact->postingNumber();
            $params["orderNumber{$i}"] = $fact->orderNumber();
            $params["warehouseId{$i}"] = $fact->warehouseId();
            $params["warehouseName{$i}"] = $fact->warehouseName();
            $params["deliveryCity{$i}"] = $fact->deliveryCity();
            $params["clusterFrom{$i}"] = $fact->clusterFrom();
            $params["clusterTo{$i}"] = $fact->clusterTo();
            $params["orderedAt{$i}"] = $fact->orderedAt()?->format('Y-m-d H:i:s');
        }

        $sql = <<<SQL
            INSERT INTO sales_fact
                (company_id, marketplace_account_id, source_row_id, business_date, status, marketplace_sku,
                 quantity, amount_minor, commission_amount_minor, currency, raw_document_id, row_hash,
                 first_loaded_at, last_updated_at, posting_number, order_number,
                 warehouse_id, warehouse_name, delivery_city, cluster_from, cluster_to, ordered_at, fact_revision, capture_initial)
            VALUES {$this->joinValues($valuesSql)}
            ON CONFLICT (company_id, marketplace_account_id, source_row_id)
            DO UPDATE SET
                fact_revision = sales_fact.fact_revision + 1,
                capture_initial = false,
                status = EXCLUDED.status,
                quantity = EXCLUDED.quantity,
                amount_minor = EXCLUDED.amount_minor,
                commission_amount_minor = EXCLUDED.commission_amount_minor,
                currency = EXCLUDED.currency,
                raw_document_id = EXCLUDED.raw_document_id,
                row_hash = EXCLUDED.row_hash,
                last_updated_at = EXCLUDED.last_updated_at
                , posting_number = EXCLUDED.posting_number
                , order_number = EXCLUDED.order_number
                , warehouse_id = EXCLUDED.warehouse_id
                , warehouse_name = EXCLUDED.warehouse_name
                , delivery_city = EXCLUDED.delivery_city
                , cluster_from = EXCLUDED.cluster_from
                , cluster_to = EXCLUDED.cluster_to
                , ordered_at = COALESCE(EXCLUDED.ordered_at, sales_fact.ordered_at)
            WHERE sales_fact.row_hash IS DISTINCT FROM EXCLUDED.row_hash
            SQL;

        $this->connection->executeStatement($this->captureSql($sql, $facts, $params, $origin, 'upsert'), $params);
    }

    /**
     * SET и WHERE конфликтной ветки бэкфилла.
     *
     * row_hash переписывается, когда строка после обновления совпадает
     * с разобранным историческим фактом во всех полях row_hash
     * (SalesFact::computeRowHash): тогда EXCLUDED.row_hash и есть хэш этой
     * строки по текущей формуле. Нужно, чтобы расширение формулы хэша
     * (атрибуты доставки, кластеры) не превращалось в мнимую корректировку
     * задним числом: без этого первая синхронизация переписала бы окно
     * с новым last_updated_at, хотя данные площадки не менялись (ADR-006).
     *
     * @return array{string, string}
     */
    private static function backfillSetAndWhere(): array
    {
        $final = [];
        foreach (self::BACKFILL_LINK_COLUMNS as $column) {
            $final[$column] = "COALESCE(sales_fact.{$column}, EXCLUDED.{$column})";
        }
        foreach (self::BACKFILL_SNAPSHOT_COLUMNS as $column) {
            $final[$column] = 'CASE WHEN '.self::SAME_RAW." THEN COALESCE(EXCLUDED.{$column}, sales_fact.{$column}) "
                ."ELSE COALESCE(sales_fact.{$column}, EXCLUDED.{$column}) END";
        }

        $snapshotMatches = ['sales_fact.status = EXCLUDED.status',
            'sales_fact.quantity = EXCLUDED.quantity',
            'sales_fact.amount_minor = EXCLUDED.amount_minor',
            'sales_fact.commission_amount_minor = EXCLUDED.commission_amount_minor',
        ];
        $set = [];
        $fills = [];
        foreach ($final as $column => $expression) {
            $snapshotMatches[] = "({$expression}) IS NOT DISTINCT FROM EXCLUDED.{$column}";
            $set[] = "{$column} = {$expression}";
            $fills[] = "(sales_fact.{$column} IS NULL AND EXCLUDED.{$column} IS NOT NULL)";
        }
        $matches = implode(' AND ', $snapshotMatches);
        $sameRawChanges = [];
        foreach (self::BACKFILL_SNAPSHOT_COLUMNS as $column) {
            $sameRawChanges[] = "(EXCLUDED.{$column} IS NOT NULL AND sales_fact.{$column} IS DISTINCT FROM EXCLUDED.{$column})";
        }

        foreach (self::BACKFILL_FILL_ONLY_COLUMNS as $column) {
            $set[] = "{$column} = COALESCE(sales_fact.{$column}, EXCLUDED.{$column})";
            $fills[] = "(sales_fact.{$column} IS NULL AND EXCLUDED.{$column} IS NOT NULL)";
        }

        array_unshift($set, "row_hash = CASE WHEN {$matches} THEN EXCLUDED.row_hash ELSE sales_fact.row_hash END");
        $where = array_merge($fills, [
            '('.self::SAME_RAW.' AND ('.implode(' OR ', $sameRawChanges).'))',
            "(sales_fact.row_hash IS DISTINCT FROM EXCLUDED.row_hash AND {$matches})",
        ]);

        return [implode(",\n    ", $set), implode("\n   OR ", $where)];
    }

    /**
     * @param list<string> $valuesSql
     */
    private function joinValues(array $valuesSql): string
    {
        return implode(', ', $valuesSql);
    }

    /**
     * Only accepted RETURNING states enter history; ledger error aborts the UPSERT.
     *
     * @param list<SalesFact>                $facts
     * @param array<string, int|string|null> $parameters
     */
    private function captureSql(string $upsert, array $facts, array &$parameters, FactCaptureOrigin $origin, string $path): string
    {
        $keys = [];
        foreach ($facts as $i => $fact) {
            $keys[] = "(CAST(:companyId{$i} AS uuid), CAST(:marketplaceAccountId{$i} AS uuid), CAST(:sourceRowId{$i} AS text), CAST(:revisionId{$i} AS uuid))";
            $parameters["revisionId{$i}"] = Uuid::v7()->toRfc4122();
        }
        $parameters['capturedAt'] = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $parameters['captureOrigin'] = $origin->value;
        $parameters['capturePath'] = $path;
        $ids = implode(', ', $keys);

        return <<<SQL
            WITH accepted AS (
                {$upsert}
                RETURNING company_id, marketplace_account_id, source_row_id, business_date, status, marketplace_sku, quantity, amount_minor, commission_amount_minor, currency, raw_document_id, row_hash, first_loaded_at, last_updated_at, posting_number, order_number, warehouse_id, warehouse_name, delivery_city, cluster_from, cluster_to, ordered_at, fact_revision, capture_initial
            )
            INSERT INTO sales_fact_revision (company_id, marketplace_account_id, source_row_id, business_date, status, marketplace_sku, quantity, amount_minor, commission_amount_minor, currency, raw_document_id, row_hash, first_loaded_at, last_updated_at, posting_number, order_number, warehouse_id, warehouse_name, delivery_city, cluster_from, cluster_to, ordered_at, fact_revision, id, captured_at, capture_version, capture_origin, capture_path, capture_is_initial)
            SELECT accepted.company_id, accepted.marketplace_account_id, accepted.source_row_id, accepted.business_date, accepted.status, accepted.marketplace_sku, accepted.quantity, accepted.amount_minor, accepted.commission_amount_minor, accepted.currency, accepted.raw_document_id, accepted.row_hash, accepted.first_loaded_at, accepted.last_updated_at, accepted.posting_number, accepted.order_number, accepted.warehouse_id, accepted.warehouse_name, accepted.delivery_city, accepted.cluster_from, accepted.cluster_to, accepted.ordered_at, accepted.fact_revision, keys.id,
                   CAST(:capturedAt AS timestamp), 'accepted_state_v1', :captureOrigin, :capturePath, accepted.capture_initial
            FROM accepted
            JOIN (VALUES {$ids}) AS keys (company_id, marketplace_account_id, source_row_id, id)
              ON keys.company_id = accepted.company_id
             AND keys.marketplace_account_id = accepted.marketplace_account_id
             AND keys.source_row_id = accepted.source_row_id
            SQL;
    }
}
