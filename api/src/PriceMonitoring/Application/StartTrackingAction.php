<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Application;

use App\Identity\Application\Facade\CompanyConnection;
use App\Identity\Application\Facade\IdentityFacade;
use App\PriceMonitoring\Domain\StartTrackingOutcome;
use App\PriceMonitoring\Domain\TrackedSku;
use App\PriceMonitoring\Domain\TrackedSkuRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Включение отслеживания артикула (ADR-014).
 *
 * Кабинет продавца определяет сервер, а не клиент: расширение знает
 * только артикул со страницы, и принимать `marketplaceAccountId` из его
 * тела значило бы позволить клиенту на чужой машине выбирать, к какому
 * подключению привязать запись.
 *
 * Вход в Identity — только через Facade (CLAUDE.md, «Модули»).
 */
final class StartTrackingAction
{
    /**
     * Потолок на компанию. Не бизнес-ограничение и не защита базы:
     * расширение обходит артикулы последовательно, размазывая визиты
     * по 30-минутному окну, а `chrome.alarms` округляет интервал вверх
     * до полуминуты. При 50 артикулах шаг — 36 секунд; дальше визиты
     * перестают размазываться и идут пачкой, то есть обещание ADR-014
     * ломается молча.
     *
     * Проверка не защищает от гонки двух одновременных кликов —
     * пятьдесят первый артикул при этом безвреден, а замок ради него
     * стоил бы дороже. Граница нужна против клиента, который шлёт
     * запросы в цикле, а не против человека, кликающего дважды.
     */
    public const int MAX_TRACKED = 50;

    /**
     * Значение колонки `marketplace`, а не enum `Marketplace` из Identity:
     * его класс лежит в `Identity\Domain`, куда PriceMonitoring не ходит
     * (зависимости строго вниз, вход в модуль — только через Facade).
     * Facade отдаёт эти поля строками намеренно — см. `CompanyConnection`.
     */
    private const string MARKETPLACE_OZON = 'ozon';
    private const string STATE_ACTIVE = 'active';

    public function __construct(
        private readonly IdentityFacade $identity,
        private readonly TrackedSkuRepository $trackedSkus,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $marketplaceSku,
        string $actorUserId,
        \DateTimeImmutable $at,
    ): StartTrackingOutcome {
        $ozon = array_values(array_filter(
            $this->identity->listConnections($companyId),
            static fn (CompanyConnection $connection): bool => self::MARKETPLACE_OZON === $connection->marketplace
                && self::STATE_ACTIVE === $connection->state,
        ));

        $account = $ozon[0] ?? null;
        if (null === $account) {
            return StartTrackingOutcome::NoActiveOzonConnection;
        }
        if (\count($ozon) > 1) {
            return StartTrackingOutcome::MultipleOzonConnections;
        }

        // Считается до записи, и потому пропускает уже отслеживаемый
        // артикул на самом потолке: повторный клик по нему упёрся бы
        // в лимит, ничего при этом не добавляя. Различить эти случаи
        // можно только лишним запросом — ради ответа, который клиент
        // всё равно показывает одинаково.
        if ($this->trackedSkus->countActive($companyId) >= self::MAX_TRACKED) {
            return StartTrackingOutcome::LimitReached;
        }

        $this->trackedSkus->track(TrackedSku::startTracking(
            companyId: Uuid::fromString($companyId),
            marketplaceAccountId: Uuid::fromString($account->id),
            marketplaceSku: $marketplaceSku,
            createdByUserId: Uuid::fromString($actorUserId),
            createdAt: $at,
        ));

        return StartTrackingOutcome::Tracked;
    }
}
