<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Domain\PlannedDay;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanLine;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use App\SalesPlanning\Domain\SkuPeriodPlan;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

/**
 * Правка одного дня SKU в черновике (ADR-040 п. 7, 8): акция с другой
 * ценой, выходной без продаж. Итог периода — сумма дней, отдельно
 * не хранится и не пересчитывается.
 *
 * День, которого в плане нет, правкой не создаётся: SKU добавляется
 * вводом за период (строки плотные). Итог SKU после правки держится
 * под тем же потолком, что и ввод за период, — иначе выручка плана
 * вышла бы за bigint.
 *
 * Версия обязательна (ADR-008); журнал — «было/стало» этого дня
 * (ADR-040 п. 10), тем же flush, что и шапка.
 */
final readonly class ChangeSalesPlanDayAction
{
    public function __construct(
        private SalesPlanRepository $plans,
        private IdentityFacade $identity,
    ) {
    }

    /**
     * @throws \InvalidArgumentException штуки или цена не годятся
     */
    public function __invoke(
        string $companyId,
        string $planId,
        int $expectedVersion,
        string $marketplaceSku,
        \DateTimeImmutable $date,
        int $quantity,
        ?int $priceMinor,
        string $actorUserId,
    ): EditSalesPlanResult {
        $plan = $this->plans->get($companyId, Uuid::fromString($planId));
        if (null === $plan) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::NotFound);
        }

        if (SalesPlanStatus::Draft !== $plan->status()) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::NotEditable);
        }

        if ($plan->version() !== $expectedVersion) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::VersionConflict);
        }

        if (!$plan->covers($date)) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::DayOutOfPeriod);
        }

        if ($quantity < 0 || $quantity > SkuPeriodPlan::MaxTotalQuantity) {
            throw new \InvalidArgumentException(\sprintf('Штук в дне — от 0 до %d.', SkuPeriodPlan::MaxTotalQuantity));
        }

        if (null !== $priceMinor) {
            $priceMinor = SalesPlanLine::validPriceMinor($plan, Money::ofMinor($priceMinor, $plan->currency()));
        }

        $days = $this->plans->daysOfSkus($companyId, $plan->id(), [$marketplaceSku])[$marketplaceSku] ?? null;
        if (null === $days) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::SkuNotInPlan);
        }

        $day = SalesPlan::calendarDay($date);
        $previous = null;
        $total = $quantity;
        foreach ($days as $stored) {
            if ($stored->date->format('Y-m-d') === $day->format('Y-m-d')) {
                $previous = $stored;
            } else {
                $total += $stored->quantity;
            }
        }

        if (null === $previous) {
            // Строки плотные: день внутри периода у SKU плана есть всегда.
            // Нет — значит, план прочитан не тот, что записан.
            return EditSalesPlanResult::of(EditSalesPlanOutcome::SkuNotInPlan);
        }

        if ($total > SkuPeriodPlan::MaxTotalQuantity) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::TotalTooLarge);
        }

        // Без цены у дня остаётся прежняя (правка одних штук).
        $changed = new PlannedDay($day, $quantity, $priceMinor ?? $previous->priceMinor);

        $this->identity->recordAuditEntry(
            companyId: $companyId,
            actorUserId: $actorUserId,
            action: SalesPlanAuditAction::DayChanged,
            subjectId: $plan->id()->toRfc4122(),
            previousValue: SkuPeriodPlan::stored($marketplaceSku, [$previous])->describe($plan),
            newValue: SkuPeriodPlan::stored($marketplaceSku, [$changed])->describe($plan),
        );

        $plan->recordLinesChanged(new \DateTimeImmutable());

        try {
            $this->plans->saveDay($plan, $marketplaceSku, $changed);
        } catch (OptimisticLockException) {
            return EditSalesPlanResult::of(EditSalesPlanOutcome::VersionConflict);
        }

        return EditSalesPlanResult::saved($plan->version());
    }
}
