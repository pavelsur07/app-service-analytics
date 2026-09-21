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
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class ApplyPlanImportAction
{
    public function __construct(
        private IdentityAccountScopeFacade $accounts,
        private IngestionPlanningFacade $ingestion,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId, string $previewId, string $actorId): ApplyPlanImportResult
    {
        if (!$this->accounts->ownsMarketplaceAccount($companyId, $marketplaceAccountId)) {
            return new ApplyPlanImportResult(PlanImportApplyOutcome::AccountNotFound);
        }

        try {
            /** @var ApplyPlanImportResult $result */
            $result = $this->entityManager->wrapInTransaction(function () use ($companyId, $marketplaceAccountId, $previewId, $actorId): ApplyPlanImportResult {
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
            });

            return $result;
        } catch (OptimisticLockException|DeadlockException|UniqueConstraintViolationException) {
            return new ApplyPlanImportResult(PlanImportApplyOutcome::Conflict);
        }
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
        $known = $this->ingestion->knownMarketplaceSkus($companyId, $marketplaceAccountId, $skus);
        sort($known);
        sort($skus);

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
            ->where('plan.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
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
