<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Domain\ListingCostAuditAction;
use App\Ingestion\Domain\ListingCostOutcome;
use App\Ingestion\Domain\MarketplaceListingCostRepository;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

/**
 * Исправление уже записанной себестоимости (ADR-013).
 *
 * **Меняет уже показанную прибыль, и это его смысл.** Опечатка в цене,
 * счёт за доставку партии, пришедший через месяц после того, как партия
 * распродана, ретро-скидка поставщика — записанное было неправдой,
 * и отчёт за прошедшие дни обязан пересчитаться.
 *
 * Отдельно от «новой цены с даты» именно поэтому: у операций
 * противоположные последствия для прошлого, и одна кнопка на оба случая
 * означала бы, что ввод сегодняшней закупки молча переписывает прибыль
 * за прошлый месяц.
 *
 * Версия обязательна (ADR-008): принимать изменение «без версии» как
 * безусловное правила прямо запрещают. Два человека, открывшие форму
 * одновременно, не должны молча затирать правку друг друга.
 */
final readonly class CorrectListingCostAction
{
    public function __construct(
        private MarketplaceListingCostRepository $costs,
        private IdentityFacade $identity,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $costId,
        Money $unitCost,
        int $expectedVersion,
        string $actorUserId,
    ): ListingCostOutcome {
        $cost = $this->costs->get($companyId, Uuid::fromString($costId));
        if (null === $cost) {
            return ListingCostOutcome::NotFound;
        }

        if ($cost->version() !== $expectedVersion) {
            return ListingCostOutcome::VersionConflict;
        }

        $previous = SetListingCostAction::describe($cost);

        $cost->correctTo($unitCost, new \DateTimeImmutable());

        // До сохранения: журнал фиксируется тем же flush, что и позиция,
        // иначе при отказе на полпути они разойдутся — а журнал, в котором
        // нет части изменений, хуже отсутствующего, ему верят.
        $this->identity->recordAuditEntry(
            companyId: $companyId,
            actorUserId: $actorUserId,
            action: ListingCostAuditAction::Corrected,
            subjectId: $costId,
            previousValue: $previous,
            newValue: SetListingCostAction::describe($cost),
        );

        try {
            $this->costs->save();
        } catch (OptimisticLockException) {
            // Версия в самом UPDATE — то, что делает гонку невозможной
            // тихо, в отличие от сверки в коде выше: между ней и записью
            // остаётся окно.
            return ListingCostOutcome::VersionConflict;
        }

        return ListingCostOutcome::Saved;
    }
}
