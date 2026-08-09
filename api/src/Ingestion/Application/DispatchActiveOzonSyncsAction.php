<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Один проход планировщика (ScheduleOzonSyncCommand, Ui): перечисляет
 * активные Ozon-подключения через IdentityFacade и диспатчит синхронизацию
 * за сегодня для каждого. В IngestionUi этот класс не живёт — Deptrac
 * не пускает Ui к IdentityFacade вообще, только Application/Infrastructure.
 *
 * Только «сегодня» — скользящее окно 45 дней и квартальный глубокий
 * рескан из ADR-006 сюда не входят, следующий шаг.
 */
final readonly class DispatchActiveOzonSyncsAction
{
    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private IdentityFacade $identityFacade,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(): int
    {
        $businessDate = (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
        $targets = $this->identityFacade->findActiveOzonSyncTargets();

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
