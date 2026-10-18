<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Facade\IngestionFacade;
use App\SalesPlanning\Domain\EvenDayDistribution;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use App\SalesPlanning\Domain\SkuPeriodPlan;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

/**
 * Ввод SKU за период в черновик (ADR-040 п. 7): штуки раскладываются
 * по дням, цена копируется. SKU, уже стоящий в плане, раскладывается
 * заново на тех же строках.
 *
 * **Один запрос на набор, а не SKU за запросом** (CLAUDE.md §6): каталог
 * проверяется одним вызовом Facade, дни всех SKU читаются одним запросом
 * и пишутся пакетом с обновлением по естественному ключу (ADR-041).
 *
 * **Версия обязательна** (ADR-008). Сверка в коде — ранний ответ;
 * защита — версия в самом UPDATE шапки, которую ORM повышает при каждой
 * правке строк (ADR-040 п. 10). Шапка обновляется первой в транзакции,
 * поэтому параллельный ввод в тот же план либо ждёт и падает на версии,
 * либо уже закоммичен — и тогда на версии падает этот.
 *
 * **Журнал** — запись на каждый SKU, «было» и «стало» — точные значения
 * дней (ADR-040 п. 10), тем же flush, что и строки.
 */
final readonly class SetSalesPlanSkusAction
{
    /**
     * Позиций в одном запросе: 100 SKU × 184 дня — около секунды по замеру
     * этапа 0.4 при пакетной записи (ADR-041); 200 SKU перераскладывались
     * 2,6 с, больше синхронного бюджета.
     */
    public const int MaxItemsPerRequest = 100;

    public function __construct(
        private SalesPlanRepository $plans,
        private IngestionFacade $ingestion,
        private IdentityFacade $identity,
        private EvenDayDistribution $distribution,
    ) {
    }

    /**
     * @param list<SkuPeriodInput> $items
     *
     * @throws \InvalidArgumentException набор пуст, слишком велик, с повтором SKU
     *                                   или с негодными штуками и ценой
     */
    public function __invoke(
        string $companyId,
        string $planId,
        int $expectedVersion,
        array $items,
        string $actorUserId,
    ): SetSalesPlanSkusResult {
        $skus = array_map(static fn (SkuPeriodInput $item): string => $item->marketplaceSku, $items);
        if ([] === $items || \count($items) > self::MaxItemsPerRequest) {
            throw new \InvalidArgumentException(\sprintf('В запросе — от 1 до %d SKU.', self::MaxItemsPerRequest));
        }

        if (\count(array_unique($skus)) !== \count($skus)) {
            throw new \InvalidArgumentException('SKU в запросе повторяется.');
        }

        $plan = $this->plans->get($companyId, Uuid::fromString($planId));
        if (null === $plan) {
            return SetSalesPlanSkusResult::of(SetSalesPlanSkusOutcome::NotFound);
        }

        if (SalesPlanStatus::Draft !== $plan->status()) {
            return SetSalesPlanSkusResult::of(SetSalesPlanSkusOutcome::NotEditable);
        }

        if ($plan->version() !== $expectedVersion) {
            return SetSalesPlanSkusResult::of(SetSalesPlanSkusOutcome::VersionConflict);
        }

        $present = $this->ingestion->presentAccountCatalogSkus(
            $companyId,
            $plan->marketplaceAccountId()->toRfc4122(),
            $skus,
        );
        $unknown = array_values(array_diff($skus, $present));
        if ([] !== $unknown) {
            sort($unknown);

            return SetSalesPlanSkusResult::unknownSkus($unknown);
        }

        $existing = $this->plans->daysOfSkus($companyId, $plan->id(), $skus);
        $added = \count(array_diff($skus, array_map('strval', array_keys($existing))));
        $skuCount = $this->plans->skuCount($companyId, $plan->id()) + $added;
        if ($skuCount > SalesPlan::MaxSkus) {
            return SetSalesPlanSkusResult::of(SetSalesPlanSkusOutcome::TooManySkus);
        }

        $skuPlans = [];
        foreach ($items as $item) {
            $skuPlan = SkuPeriodPlan::spread(
                $plan,
                $item->marketplaceSku,
                $item->totalQuantity,
                Money::ofMinor($item->priceMinor, $plan->currency()),
                $this->distribution,
            );
            $before = $existing[$item->marketplaceSku] ?? null;

            $this->identity->recordAuditEntry(
                companyId: $companyId,
                actorUserId: $actorUserId,
                action: SalesPlanAuditAction::SkuSet,
                subjectId: $plan->id()->toRfc4122(),
                previousValue: null === $before ? null : SkuPeriodPlan::stored($item->marketplaceSku, $before)->describe($plan),
                newValue: $skuPlan->describe($plan),
            );
            $skuPlans[] = $skuPlan;
        }

        // Одна отметка на действие: версия шапки растёт, и параллельный
        // ввод в тот же план упрётся в неё (ADR-040 п. 10).
        $plan->recordLinesChanged(new \DateTimeImmutable());

        try {
            $this->plans->saveSkuPlans($plan, $skuPlans);
        } catch (OptimisticLockException) {
            return SetSalesPlanSkusResult::of(SetSalesPlanSkusOutcome::VersionConflict);
        }

        return SetSalesPlanSkusResult::saved($plan->version());
    }
}
