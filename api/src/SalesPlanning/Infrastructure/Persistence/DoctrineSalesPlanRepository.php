<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Persistence;

use App\SalesPlanning\Domain\ClaimedSalesPlanDay;
use App\SalesPlanning\Domain\EmptySalesPlan;
use App\SalesPlanning\Domain\PlannedDay;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanApprovalConflict;
use App\SalesPlanning\Domain\SalesPlanLine;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ORM, а не DBAL: план вводит человек (CLAUDE.md §6), ему нужны версия
 * (ADR-008) и запись аудит-журнала той же транзакцией (ADR-011).
 */
final readonly class DoctrineSalesPlanRepository implements SalesPlanRepository
{
    private const int FlushBatchSize = 1000;

    // Порция — один JSON-параметр; 10 000 строк — около мегабайта тела
    // запроса, без заметной памяти на стороне базы.
    private const int UpsertBatchSize = 10_000;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(SalesPlan $plan, array $lines): void
    {
        $this->assertOwnLines($plan, $lines);

        $this->entityManager->wrapInTransaction(function () use ($plan, $lines): void {
            $this->entityManager->persist($plan);
            $this->entityManager->flush();
            $this->persistInBatches($lines);
        });
    }

    public function transactional(callable $operation): mixed
    {
        return $this->entityManager->wrapInTransaction($operation);
    }

    public function openDraft(string $companyId, Uuid $lineageId): ?SalesPlan
    {
        return $this->lineagePlan($companyId, $lineageId, SalesPlanStatus::Draft);
    }

    public function activeApproved(string $companyId, Uuid $lineageId): ?SalesPlan
    {
        return $this->lineagePlan($companyId, $lineageId, SalesPlanStatus::Approved);
    }

    private function lineagePlan(string $companyId, Uuid $lineageId, SalesPlanStatus $status): ?SalesPlan
    {
        $plan = $this->entityManager->createQuery('SELECT p FROM '.SalesPlan::class.' p WHERE p.companyId = :companyId AND p.lineageId = :lineageId AND p.status = :status')
            ->setParameter('companyId', Uuid::fromString($companyId), 'uuid')
            ->setParameter('lineageId', $lineageId, 'uuid')->setParameter('status', $status->value)->setMaxResults(1)->getOneOrNullResult();
        \assert(null === $plan || $plan instanceof SalesPlan);

        return $plan;
    }

    public function nextVersionNumber(string $companyId, Uuid $lineageId): int
    {
        $maximum = $this->entityManager->getConnection()->fetchOne('SELECT COALESCE(MAX(version_number), 0) FROM sales_plan WHERE company_id = :companyId AND lineage_id = :lineageId', ['companyId' => $companyId, 'lineageId' => $lineageId->toRfc4122()]);
        \assert(\is_int($maximum));
        if ($maximum >= 2147483647) {
            throw new \DomainException('Номер версии плана исчерпан.');
        }

        return $maximum + 1;
    }

    public function copyRevision(string $companyId, SalesPlan $source, SalesPlan $revision): void
    {
        if (!$source->companyId()->equals(Uuid::fromString($companyId))
            || !$revision->companyId()->equals($source->companyId())
            || !$revision->lineageId()->equals($source->lineageId())
            || !$revision->marketplaceAccountId()->equals($source->marketplaceAccountId())
            || $revision->id()->equals($source->id()) || $revision->name() !== $source->name()
            || $revision->dateFrom()->format('Y-m-d') !== $source->dateFrom()->format('Y-m-d')
            || $revision->dateTo()->format('Y-m-d') !== $source->dateTo()->format('Y-m-d')
            || $revision->currency() !== $source->currency() || $revision->metric() !== $source->metric()
            || $revision->versionNumber() <= $source->versionNumber()
            || SalesPlanStatus::Approved !== $source->status()
            || SalesPlanStatus::Draft !== $revision->status()) {
            throw new \InvalidArgumentException('Новая версия не соответствует утверждённому источнику.');
        }
        $parameters = ['companyId' => $companyId, 'sourceId' => $source->id()->toRfc4122(), 'targetId' => $revision->id()->toRfc4122()];
        $connection = $this->entityManager->getConnection();
        $count = $connection->fetchOne('SELECT COUNT(*) FROM sales_plan_line WHERE company_id = :companyId AND sales_plan_id = :sourceId', ['companyId' => $companyId, 'sourceId' => $parameters['sourceId']]);
        \assert(\is_int($count));
        if ($count > SalesPlan::MaxSkus * SalesPlan::MaxPeriodDays) {
            throw new \DomainException('Число дней плана превышает предел.');
        }
        $this->entityManager->persist($revision);
        $this->entityManager->flush();
        $after = null;
        for ($remaining = $count; $remaining > 0; $remaining -= $batchSize) {
            $batchSize = min(self::UpsertBatchSize, $remaining);
            $ids = [];
            for ($i = 0; $i < $batchSize; ++$i) {
                $ids[] = Uuid::v7()->toRfc4122();
            }
            $batchParameters = $parameters;
            $batchParameters['ids'] = json_encode($ids, \JSON_THROW_ON_ERROR);
            $afterCondition = '';
            if (null !== $after) {
                $afterCondition = ' AND (marketplace_sku, plan_date) > (:afterSku, CAST(:afterDate AS date))';
                $batchParameters['afterSku'] = $after['marketplace_sku'];
                $batchParameters['afterDate'] = $after['plan_date'];
            }
            // ADR-041: не более 10000 UUID/строк за statement, значения дней
            // остаются в БД. Следующая порция начинается после возвращённого ключа.
            /** @var array{marketplace_sku: string, plan_date: string}|false $after */
            $after = $connection->fetchAssociative(
                'WITH chunk AS MATERIALIZED (
                    SELECT marketplace_sku, plan_date, quantity, price_minor FROM sales_plan_line
                    WHERE company_id = :companyId AND sales_plan_id = :sourceId'.$afterCondition.'
                    ORDER BY marketplace_sku, plan_date LIMIT '.self::UpsertBatchSize.'
                 ), inserted AS (
                    INSERT INTO sales_plan_line (id, company_id, sales_plan_id, marketplace_sku, plan_date, quantity, price_minor)
                    SELECT CAST(ids.id AS uuid), :companyId, :targetId, s.marketplace_sku, s.plan_date, s.quantity, s.price_minor
                    FROM (SELECT *, ROW_NUMBER() OVER (ORDER BY marketplace_sku, plan_date) AS n FROM chunk) s
                    JOIN jsonb_array_elements_text(CAST(:ids AS jsonb)) WITH ORDINALITY AS ids(id, n) ON ids.n = s.n
                    RETURNING marketplace_sku, plan_date
                 )
                 SELECT marketplace_sku, plan_date FROM inserted ORDER BY marketplace_sku DESC, plan_date DESC LIMIT 1',
                $batchParameters,
            );
            if (false === $after) {
                throw new \DomainException('Строки утверждённого источника изменились во время копирования.');
            }
        }
    }

    public function releaseClaims(string $companyId, SalesPlan $source): void
    {
        if (!$source->companyId()->equals(Uuid::fromString($companyId))) {
            throw new \InvalidArgumentException('План принадлежит другой компании.');
        }
        $this->entityManager->getConnection()->executeStatement('DELETE FROM sales_plan_day_claim WHERE company_id = :companyId AND sales_plan_id = :planId', ['companyId' => $companyId, 'planId' => $source->id()->toRfc4122()]);
    }

    public function saveMetadata(SalesPlan $plan): void
    {
        $this->entityManager->wrapInTransaction(function (): void {
            $this->entityManager->flush();
        });
    }

    public function approve(string $companyId, SalesPlan $plan): void
    {
        if (!$plan->companyId()->equals(Uuid::fromString($companyId))) {
            throw new \InvalidArgumentException('План принадлежит другой компании.');
        }

        $this->entityManager->wrapInTransaction(function () use ($companyId, $plan): void {
            // UPDATE шапки сериализует утверждение с любыми правками строк.
            // Версия и аудит сбрасываются до чтения/занятия дней.
            $this->entityManager->flush();
            $connection = $this->entityManager->getConnection();
            $parameters = [
                'companyId' => $companyId,
                'planId' => $plan->id()->toRfc4122(),
                'accountId' => $plan->marketplaceAccountId()->toRfc4122(),
            ];
            $sourceCount = $connection->fetchOne(
                'SELECT COUNT(*) FROM sales_plan_line WHERE company_id = :companyId AND sales_plan_id = :planId',
                ['companyId' => $parameters['companyId'], 'planId' => $parameters['planId']],
            );
            \assert(\is_int($sourceCount));
            if (0 === $sourceCount) {
                throw new EmptySalesPlan('Пустой план нельзя утвердить.');
            }
            $inserted = $connection->executeStatement(
                'INSERT INTO sales_plan_day_claim (company_id, marketplace_account_id, marketplace_sku, plan_date, sales_plan_id)
                 SELECT company_id, :accountId, marketplace_sku, plan_date, sales_plan_id
                   FROM sales_plan_line WHERE company_id = :companyId AND sales_plan_id = :planId
                  ORDER BY marketplace_sku, plan_date
                 ON CONFLICT (company_id, marketplace_account_id, marketplace_sku, plan_date) DO NOTHING',
                $parameters,
            );
            if ($inserted !== $sourceCount) {
                /** @var list<array{marketplace_sku: string, plan_date: string}> $rows */
                $rows = $connection->fetchAllAssociative(
                    'SELECT l.marketplace_sku, l.plan_date FROM sales_plan_line l
                     JOIN sales_plan_day_claim c ON c.company_id = l.company_id
                      AND c.marketplace_account_id = :accountId AND c.marketplace_sku = l.marketplace_sku AND c.plan_date = l.plan_date
                     WHERE l.company_id = :companyId AND l.sales_plan_id = :planId AND c.sales_plan_id <> :planId
                     ORDER BY l.marketplace_sku, l.plan_date LIMIT 200',
                    $parameters,
                );
                throw new SalesPlanApprovalConflict(array_map(static fn (array $row): ClaimedSalesPlanDay => new ClaimedSalesPlanDay($row['marketplace_sku'], $row['plan_date']), $rows));
            }
        });
    }

    public function saveSkuPlans(SalesPlan $plan, array $skuPlans): void
    {
        $this->entityManager->wrapInTransaction(function () use ($plan, $skuPlans): void {
            // Сначала шапка: версия проверяется в самом UPDATE, и
            // конфликт обнаруживается до записи строк — транзакция
            // откатывается целиком. Тот же flush пишет журнал.
            $this->entityManager->flush();

            $rows = [];
            foreach ($skuPlans as $skuPlan) {
                foreach ($skuPlan->days as $day) {
                    $rows[] = [$skuPlan->marketplaceSku, $day];
                }
            }

            foreach (array_chunk($rows, self::UpsertBatchSize) as $batch) {
                $this->upsertDays($plan, $batch);
            }
        });
    }

    public function saveDay(SalesPlan $plan, string $marketplaceSku, PlannedDay $day): void
    {
        $this->entityManager->wrapInTransaction(function () use ($plan, $marketplaceSku, $day): void {
            // Шапка первой: версия проверяется в самом UPDATE (ADR-008).
            $this->entityManager->flush();

            $this->entityManager->getConnection()->executeStatement(
                'UPDATE sales_plan_line SET quantity = :quantity, price_minor = :price
                  WHERE company_id = :companyId AND sales_plan_id = :planId
                    AND marketplace_sku = :sku AND plan_date = :date',
                [
                    'quantity' => $day->quantity,
                    'price' => $day->priceMinor,
                    'companyId' => $plan->companyId()->toRfc4122(),
                    'planId' => $plan->id()->toRfc4122(),
                    'sku' => $marketplaceSku,
                    'date' => $day->date->format('Y-m-d'),
                ],
            );
        });
    }

    public function removeSku(SalesPlan $plan, string $marketplaceSku): void
    {
        $this->entityManager->wrapInTransaction(function () use ($plan, $marketplaceSku): void {
            $this->entityManager->flush();

            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM sales_plan_line
                  WHERE company_id = :companyId AND sales_plan_id = :planId AND marketplace_sku = :sku',
                [
                    'companyId' => $plan->companyId()->toRfc4122(),
                    'planId' => $plan->id()->toRfc4122(),
                    'sku' => $marketplaceSku,
                ],
            );
        });
    }

    public function daysOfSkus(string $companyId, Uuid $planId, array $marketplaceSkus): array
    {
        if ([] === $marketplaceSkus) {
            return [];
        }

        /** @var list<array{marketplace_sku: string, plan_date: string, quantity: int, price_minor: int}> $rows */
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT marketplace_sku, plan_date, quantity, price_minor
               FROM sales_plan_line
              WHERE company_id = :companyId AND sales_plan_id = :planId AND marketplace_sku IN (:skus)
              ORDER BY marketplace_sku, plan_date',
            ['companyId' => $companyId, 'planId' => $planId->toRfc4122(), 'skus' => array_values(array_unique($marketplaceSkus))],
            ['skus' => ArrayParameterType::STRING],
        );

        $bySku = [];
        foreach ($rows as $row) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $row['plan_date'], new \DateTimeZone('UTC'));
            \assert(false !== $date);
            $bySku[$row['marketplace_sku']][] = new PlannedDay($date, $row['quantity'], $row['price_minor']);
        }

        return $bySku;
    }

    public function skuCount(string $companyId, Uuid $planId): int
    {
        $count = $this->entityManager->getConnection()->fetchOne(
            'SELECT COUNT(DISTINCT marketplace_sku) FROM sales_plan_line WHERE company_id = :companyId AND sales_plan_id = :planId',
            ['companyId' => $companyId, 'planId' => $planId->toRfc4122()],
        );
        \assert(\is_int($count));

        return $count;
    }

    /**
     * @param list<SalesPlanLine> $lines
     */
    private function assertOwnLines(SalesPlan $plan, array $lines): void
    {
        // Строки чужого плана — тем более чужой компании — сюда не
        // попадут: склейка строк под не той шапкой обошла бы и границу
        // согласованности (ADR-040 п. 10), и изоляцию (CLAUDE.md §1).
        // Внешнего ключа нет по причине ADR-022: Doctrine не видит
        // FK скалярных UUID-ссылок и предлагает удалить его в diff.
        foreach ($lines as $line) {
            if (!$line->salesPlanId()->equals($plan->id()) || !$line->companyId()->equals($plan->companyId())) {
                throw new \InvalidArgumentException('Строка принадлежит другому плану.');
            }
        }
    }

    /**
     * @param list<SalesPlanLine> $lines
     */
    private function persistInBatches(array $lines): void
    {
        foreach (array_chunk($lines, self::FlushBatchSize) as $batch) {
            foreach ($batch as $line) {
                $this->entityManager->persist($line);
            }

            $this->entityManager->flush();

            // Сброшенные строки больше не нужны единице работы:
            // без отсоединения память росла бы с числом строк,
            // и порционный flush ничего бы не экономил.
            foreach ($batch as $line) {
                $this->entityManager->detach($line);
            }
        }
    }

    /**
     * Пакетная запись дней (ADR-041): порция уходит одним параметром —
     * JSON-массивом, который PostgreSQL разворачивает в строки
     * (`jsonb_to_recordset`). Тысячи именованных параметров разбирались
     * бы дольше самой вставки. Строка, уже стоящая на том же SKU × дне
     * плана, обновляется по уникальному ключу — не «проверить, затем
     * записать» (CLAUDE.md §4). id новой строки — UUIDv7 из приложения
     * (§2); у существующей остаётся прежний.
     *
     * @param list<array{string, PlannedDay}> $rows
     */
    private function upsertDays(SalesPlan $plan, array $rows): void
    {
        $records = array_map(
            static fn (array $row): array => [
                'id' => Uuid::v7()->toRfc4122(),
                'marketplace_sku' => $row[0],
                'plan_date' => $row[1]->date->format('Y-m-d'),
                'quantity' => $row[1]->quantity,
                'price_minor' => $row[1]->priceMinor,
            ],
            $rows,
        );

        $this->entityManager->getConnection()->executeStatement(
            'INSERT INTO sales_plan_line (id, company_id, sales_plan_id, marketplace_sku, plan_date, quantity, price_minor)
             SELECT r.id, :companyId, :planId, r.marketplace_sku, r.plan_date, r.quantity, r.price_minor
               FROM jsonb_to_recordset(CAST(:rows AS jsonb))
                 AS r(id uuid, marketplace_sku varchar(64), plan_date date, quantity int, price_minor bigint)
             ON CONFLICT (company_id, sales_plan_id, marketplace_sku, plan_date)
             DO UPDATE SET quantity = EXCLUDED.quantity, price_minor = EXCLUDED.price_minor',
            [
                'companyId' => $plan->companyId()->toRfc4122(),
                'planId' => $plan->id()->toRfc4122(),
                'rows' => json_encode($records, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            ],
        );
    }

    public function get(string $companyId, Uuid $id): ?SalesPlan
    {
        // Условие по компании в самом запросе, а не проверкой после
        // загрузки: найти чужую строку и отбросить её в PHP — это уже
        // прочитать чужие данные (CLAUDE.md §1).
        $plan = $this->entityManager
            ->createQuery(
                'SELECT p FROM '.SalesPlan::class.' p WHERE p.companyId = :companyId AND p.id = :id',
            )
            ->setParameter('companyId', Uuid::fromString($companyId), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getOneOrNullResult();

        \assert(null === $plan || $plan instanceof SalesPlan);

        return $plan;
    }
}
