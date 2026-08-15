<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Domain\ListingCostAuditAction;
use App\Ingestion\Domain\ListingCostOutcome;
use App\Ingestion\Domain\MarketplaceListingCost;
use App\Ingestion\Domain\MarketplaceListingCostRepository;
use App\Shared\Domain\ValueObject\Money;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Uuid;

/**
 * Новая себестоимость, действующая с даты (ADR-013).
 *
 * **Прошлое не трогает.** Это первое из двух событий, которые прячутся
 * за словами «себестоимость изменилась»: купили новую партию по другой
 * цене. Товар, проданный раньше, стоил столько, сколько стоил, и новая
 * поставка не имеет права переписать прибыль за прошлый месяц.
 *
 * Второе событие — исправление уже записанного — делает отдельный
 * сценарий (CorrectListingCostAction). Одна операция на оба случая
 * ошибается предсказуемо: пользователь думает, что заводит новую цену,
 * а переписывает историю.
 *
 * Аудит обязателен (CLAUDE.md, «Безопасность и аудит»): себестоимость
 * названа там прямо. Запись ставится до сохранения — фиксирует её тот же
 * flush, что и саму позицию.
 */
final readonly class SetListingCostAction
{
    public function __construct(
        private MarketplaceListingCostRepository $costs,
        private IdentityFacade $identity,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $effectiveFrom,
        Money $unitCost,
        string $actorUserId,
    ): ListingCostOutcome {
        $cost = MarketplaceListingCost::pricedFrom(
            companyId: Uuid::fromString($companyId),
            marketplaceAccountId: Uuid::fromString($marketplaceAccountId),
            marketplaceSku: $marketplaceSku,
            effectiveFrom: $effectiveFrom,
            unitCost: $unitCost,
            recordedAt: new \DateTimeImmutable(),
        );

        $this->identity->recordAuditEntry(
            companyId: $companyId,
            actorUserId: $actorUserId,
            action: ListingCostAuditAction::Set,
            subjectId: $cost->id()->toRfc4122(),
            previousValue: null,
            newValue: self::describe($cost),
        );

        try {
            $this->costs->add($cost);
        } catch (UniqueConstraintViolationException) {
            // Уникальный индекс, а не проверка существования перед
            // вставкой: между проверкой и вставкой два параллельных
            // запроса прошли бы её оба (CLAUDE.md §4). Правило написано
            // для очереди, но причина у него та же и здесь.
            return ListingCostOutcome::AlreadySetForThatDate;
        }

        return ListingCostOutcome::Saved;
    }

    /**
     * Что записать в журнал. Сумма и дата начала действия — то, ради чего
     * запись и делается: «была такая-то, стала такая-то» без них
     * отвечает на «кто», но не на «что изменилось» (ADR-011).
     */
    public static function describe(MarketplaceListingCost $cost): string
    {
        return \sprintf(
            '%d %s с %s',
            $cost->unitCost()->minorAmount(),
            $cost->unitCost()->currency(),
            $cost->effectiveFrom()->format('Y-m-d'),
        );
    }
}
