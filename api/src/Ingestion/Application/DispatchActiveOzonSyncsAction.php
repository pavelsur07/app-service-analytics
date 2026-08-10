<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityScheduleFacade;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Один проход планировщика (ScheduleOzonSyncCommand, Ui): перечисляет
 * активные Ozon-подключения через IdentityScheduleFacade и диспатчит
 * синхронизацию за сегодня для каждого. Этот класс живёт в своём узком
 * слое Deptrac (IngestionOperationalAction) отдельно от остального
 * IngestionApplication — иначе широкий доступ к IngestionApplication
 * (весь IngestionUi) транзитивно открыл бы межарендаторное чтение
 * IdentityScheduleFacade любому будущему HTTP-контроллеру.
 *
 * Только «сегодня» — скользящее окно 45 дней и квартальный глубокий
 * рескан из ADR-006 сюда не входят, следующий шаг.
 */
final readonly class DispatchActiveOzonSyncsAction
{
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private IdentityScheduleFacade $identitySchedule,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(): int
    {
        $businessDate = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
        $targets = $this->identitySchedule->findActiveOzonSyncTargets();

        foreach ($targets as $target) {
            $this->bus->dispatch(new FetchOzonPostingsMessage(
                companyId: $target->companyId,
                marketplaceAccountId: $target->marketplaceAccountId,
                businessDate: $businessDate,
            ));
        }

        return \count($targets);
    }
}
