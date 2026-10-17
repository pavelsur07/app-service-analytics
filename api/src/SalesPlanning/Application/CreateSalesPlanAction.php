<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Новый план — черновик без строк (ADR-040 п. 8). SKU вводятся
 * следующим шагом (SetSalesPlanSkusAction): у шапки своя версия,
 * и ввод идёт уже по ней.
 *
 * Подключение проверяется по подключениям компании через Identity:
 * план на чужое или отозванное подключение не заводится. Чужое
 * и не найдётся — список подключений читается по companyId (§1).
 *
 * Журнал — тем же flush, что и шапка (ADR-011).
 */
final readonly class CreateSalesPlanAction
{
    public function __construct(
        private SalesPlanRepository $plans,
        private IdentityFacade $identity,
    ) {
    }

    /**
     * @return SalesPlan|null null — у компании нет такого действующего подключения
     *
     * @throws \InvalidArgumentException период, название или валюта не годятся
     */
    public function __invoke(
        string $companyId,
        string $marketplaceAccountId,
        string $name,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
        string $currency,
        string $actorUserId,
    ): ?SalesPlan {
        if (!$this->isUsableConnection($companyId, $marketplaceAccountId)) {
            return null;
        }

        $plan = SalesPlan::draft(
            companyId: Uuid::fromString($companyId),
            marketplaceAccountId: Uuid::fromString($marketplaceAccountId),
            name: $name,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            currency: $currency,
            createdByUserId: Uuid::fromString($actorUserId),
            createdAt: new \DateTimeImmutable(),
        );

        $this->identity->recordAuditEntry(
            companyId: $companyId,
            actorUserId: $actorUserId,
            action: SalesPlanAuditAction::Created,
            subjectId: $plan->id()->toRfc4122(),
            previousValue: null,
            newValue: json_encode([
                'marketplaceAccountId' => $marketplaceAccountId,
                'name' => $plan->name(),
                'dateFrom' => $plan->dateFrom()->format('Y-m-d'),
                'dateTo' => $plan->dateTo()->format('Y-m-d'),
                'currency' => $plan->currency(),
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
        );

        $this->plans->add($plan, []);

        return $plan;
    }

    private function isUsableConnection(string $companyId, string $marketplaceAccountId): bool
    {
        foreach ($this->identity->listConnections($companyId) as $connection) {
            if ($connection->id === $marketplaceAccountId) {
                // Отозванное подключение больше не синхронизируется
                // (ADR-007): план на него сравнивать будет не с чем.
                return 'revoked' !== $connection->state;
            }
        }

        return false;
    }
}
