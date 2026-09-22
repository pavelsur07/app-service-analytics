<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Identity\Application\Facade\IdentityAccountScopeFacade;
use App\Ingestion\Application\Facade\IngestionPlanningFacade;
use App\Planning\Domain\DailyPlan;
use App\Planning\Domain\PlanChange;
use App\Planning\Domain\PlanImportPreview;
use App\Planning\Domain\PlanImportPreviewRow;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

final readonly class ApplyPlanImportAction
{
    private const string APPLY_STATEMENT_TIMEOUT = '25s';
    private const string APPLY_LOCK_TIMEOUT = '1s';

    public function __construct(
        private IdentityAccountScopeFacade $accounts,
        private IngestionPlanningFacade $ingestion,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ManagerRegistry $managers,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId, string $previewId, string $actorId): ApplyPlanImportResult
    {
        if (!$this->accounts->ownsMarketplaceAccount($companyId, $marketplaceAccountId)) {
            return new ApplyPlanImportResult(PlanImportApplyOutcome::AccountNotFound);
        }

        $work = function () use ($companyId, $marketplaceAccountId, $previewId, $actorId): ApplyPlanImportResult {
            $preview = $this->lockedPreview($companyId, $marketplaceAccountId, $previewId);
            if (null === $preview || $preview->actorId()->toRfc4122() !== $actorId) {
                return new ApplyPlanImportResult(PlanImportApplyOutcome::NotFound);
            }
            if (PlanImportPreview::STATUS_APPLIED === $preview->status()) {
                return new ApplyPlanImportResult(PlanImportApplyOutcome::Applied, $preview->result());
            }
            if ($preview->isExpired(new \DateTimeImmutable())) {
                return new ApplyPlanImportResult(PlanImportApplyOutcome::NotFound);
            }
            $rows = $preview->rows();
            if (!$this->allSkusStillKnown($companyId, $marketplaceAccountId, $rows)) {
                return new ApplyPlanImportResult(PlanImportApplyOutcome::Conflict);
            }
            $plans = $this->lockedPlans($companyId, $marketplaceAccountId, $rows);
            foreach ($rows as $row) {
                $current = $plans[self::key($row->marketplaceSku, $row->businessDate)] ?? null;
                if ((null === $current && 0 !== $row->expectedVersion) || (null !== $current && $current->version() !== $row->expectedVersion)) {
                    return new ApplyPlanImportResult(PlanImportApplyOutcome::Conflict);
                }
            }

            $created = 0;
            $updated = 0;
            $unchanged = 0;
            $pending = [];
            $actor = Uuid::fromString($actorId);
            $now = new \DateTimeImmutable();
            foreach ($rows as $row) {
                $key = self::key($row->marketplaceSku, $row->businessDate);
                $current = $plans[$key] ?? null;
                if (null === $current) {
                    $plan = DailyPlan::create(
                        Uuid::fromString($companyId), Uuid::fromString($marketplaceAccountId), $row->marketplaceSku,
                        new \DateTimeImmutable($row->businessDate), $row->quantity, $actor, $now,
                    );
                    $this->entityManager->persist($plan);
                    $change = PlanChange::created($plan);
                    $this->entityManager->persist($change);
                    array_push($pending, $plan, $change);
                    ++$created;
                } elseif ($current->quantity() === $row->quantity) {
                    ++$unchanged;
                } else {
                    $oldQuantity = $current->quantity();
                    $oldVersion = $current->version();
                    $current->changeQuantity($row->quantity, $actor, $now);
                    $change = PlanChange::changed($current, $oldQuantity, $oldVersion);
                    $this->entityManager->persist($change);
                    array_push($pending, $current, $change);
                    ++$updated;
                }
                if (\count($pending) >= 400) {
                    $this->flushAndDetach($pending);
                }
            }
            $this->flushAndDetach($pending);
            $summary = ['created' => $created, 'updated' => $updated, 'unchanged' => $unchanged];
            $preview->markApplied($summary, $now);

            return new ApplyPlanImportResult(PlanImportApplyOutcome::Applied, $summary);
        };

        $inOuterTransaction = $this->connection->isTransactionActive();
        if ($inOuterTransaction) {
            return $this->runInExistingTransaction($work);
        }

        $settings = $this->captureApplyTimeouts();
        $this->connection->beginTransaction();
        try {
            $this->configureApplyTimeouts();
            $result = $work();
            $this->entityManager->flush();
            $this->restoreApplyTimeouts($settings);
            $this->connection->commit();

            return $result;
        } catch (\Throwable $failure) {
            $this->rollbackAfterFailure();
            if ($this->isRetryableFailure($failure)) {
                return new ApplyPlanImportResult(PlanImportApplyOutcome::Conflict);
            }

            throw $failure;
        }
    }

    /** @param \Closure(): ApplyPlanImportResult $work */
    private function runInExistingTransaction(\Closure $work): ApplyPlanImportResult
    {
        $savepoint = 'planning_apply';
        $settings = $this->connection->fetchAssociative("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
        if (!\is_array($settings) || !\is_string($settings['statement_timeout'] ?? null) || !\is_string($settings['lock_timeout'] ?? null)) {
            throw new \UnexpectedValueException('Не удалось сохранить настройки таймаутов apply.');
        }
        $this->connection->createSavepoint($savepoint);
        try {
            $this->configureApplyTimeouts();
            $result = $work();
            $this->entityManager->flush();
            $this->restoreApplyTimeouts($settings);
            $this->connection->releaseSavepoint($savepoint);

            return $result;
        } catch (\Throwable $failure) {
            $this->connection->rollbackSavepoint($savepoint);
            $this->connection->releaseSavepoint($savepoint);

            if ($this->isRetryableFailure($failure)) {
                // The surrounding transaction owns the caller's UnitOfWork.
                // A flush failure closes the shared manager, so it must be
                // propagated and the transaction owner must roll back. A
                // lock conflict before flush can safely become Conflict.
                if (!$this->entityManager->isOpen()) {
                    throw $failure;
                }

                return new ApplyPlanImportResult(PlanImportApplyOutcome::Conflict);
            }

            throw $failure;
        }
    }

    private function configureApplyTimeouts(): void
    {
        $this->connection->executeStatement("SET LOCAL statement_timeout = '".self::APPLY_STATEMENT_TIMEOUT."'");
        $this->connection->executeStatement("SET LOCAL lock_timeout = '".self::APPLY_LOCK_TIMEOUT."'");
    }

    /** @return array{statement_timeout: string, lock_timeout: string} */
    private function captureApplyTimeouts(): array
    {
        $settings = $this->connection->fetchAssociative("SELECT current_setting('statement_timeout') AS statement_timeout, current_setting('lock_timeout') AS lock_timeout");
        if (!\is_array($settings) || !\is_string($settings['statement_timeout'] ?? null) || !\is_string($settings['lock_timeout'] ?? null)) {
            throw new \UnexpectedValueException('Не удалось сохранить настройки таймаутов apply.');
        }

        return ['statement_timeout' => $settings['statement_timeout'], 'lock_timeout' => $settings['lock_timeout']];
    }

    /** @param array{statement_timeout: string, lock_timeout: string} $settings */
    private function restoreApplyTimeouts(array $settings): void
    {
        $this->connection->executeStatement("SET LOCAL statement_timeout = '".$settings['statement_timeout']."'");
        $this->connection->executeStatement("SET LOCAL lock_timeout = '".$settings['lock_timeout']."'");
    }

    private function rollbackAfterFailure(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        $this->recoverEntityManager();
    }

    private function recoverEntityManager(): void
    {
        if (!$this->entityManager->isOpen()) {
            $this->managers->resetManager();
        }
    }

    private function isRetryableFailure(\Throwable $failure): bool
    {
        return $failure instanceof OptimisticLockException
            || ($failure instanceof DriverException && \in_array($failure->getSQLState(), ['23505', '40001', '40P01', '55P03', '57014'], true));
    }

    private function lockedPreview(string $companyId, string $marketplaceAccountId, string $previewId): ?PlanImportPreview
    {
        $preview = $this->entityManager->createQueryBuilder()->select('preview')->from(PlanImportPreview::class, 'preview')
            ->where('preview.id = :id')->andWhere('preview.companyId = :company')->andWhere('preview.marketplaceAccountId = :account')
            ->setParameter('id', $previewId, 'uuid')->setParameter('company', $companyId, 'uuid')->setParameter('account', $marketplaceAccountId, 'uuid')
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
        \assert(null === $preview || $preview instanceof PlanImportPreview);

        return $preview;
    }

    /** @param list<PlanImportPreviewRow> $rows */
    private function allSkusStillKnown(string $companyId, string $marketplaceAccountId, array $rows): bool
    {
        $skus = array_values(array_unique(array_map(static fn (PlanImportPreviewRow $row): string => $row->marketplaceSku, $rows)));
        $known = $this->ingestion->knownMarketplaceSkusForPlanImport($companyId, $marketplaceAccountId, $skus);
        sort($known, \SORT_STRING);
        sort($skus, \SORT_STRING);

        return $known === $skus;
    }

    /**
     * @param list<PlanImportPreviewRow> $rows
     *
     * @return array<string, DailyPlan>
     */
    private function lockedPlans(string $companyId, string $marketplaceAccountId, array $rows): array
    {
        if ([] === $rows) {
            return [];
        }
        $requested = array_map(static fn (PlanImportPreviewRow $row): array => ['marketplace_sku' => $row->marketplaceSku, 'business_date' => $row->businessDate], $rows);
        $ids = $this->connection->executeQuery(<<<'SQL'
            WITH requested AS (
              SELECT marketplace_sku, business_date
              FROM jsonb_to_recordset(:rows::jsonb) AS input(marketplace_sku TEXT, business_date DATE)
            )
            SELECT plan.id::text
            FROM planning_daily_plan plan
            INNER JOIN requested
              ON requested.marketplace_sku = plan.marketplace_sku
             AND requested.business_date = plan.business_date
            WHERE plan.company_id = :company AND plan.marketplace_account_id = :account
            ORDER BY plan.marketplace_sku, plan.business_date
            FOR UPDATE OF plan
            SQL, ['rows' => json_encode($requested, \JSON_THROW_ON_ERROR), 'company' => $companyId, 'account' => $marketplaceAccountId])->fetchFirstColumn();
        if ([] === $ids) {
            return [];
        }
        /** @var list<DailyPlan> $entities */
        $entities = $this->entityManager->createQueryBuilder()->select('plan')->from(DailyPlan::class, 'plan')
            ->where('plan.id IN (:ids)')
            ->andWhere('plan.companyId = :company')
            ->andWhere('plan.marketplaceAccountId = :account')
            ->setParameter('ids', $ids)
            ->setParameter('company', $companyId)
            ->setParameter('account', $marketplaceAccountId)
            ->getQuery()->getResult();
        $plans = [];
        foreach ($entities as $plan) {
            if (!$plan instanceof DailyPlan) {
                throw new \UnexpectedValueException('Запрос плана вернул некорректный объект.');
            }
            $plans[self::key($plan->marketplaceSku(), $plan->businessDate()->format('Y-m-d'))] = $plan;
        }

        return $plans;
    }

    private static function key(string $sku, string $date): string
    {
        return $sku."\0".$date;
    }

    /** @param list<object> $entities */
    private function flushAndDetach(array &$entities): void
    {
        if ([] === $entities) {
            return;
        }
        $this->entityManager->flush();
        foreach ($entities as $entity) {
            $this->entityManager->detach($entity);
        }
        $entities = [];
    }
}
