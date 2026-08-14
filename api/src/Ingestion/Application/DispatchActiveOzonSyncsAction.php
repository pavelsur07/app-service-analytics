<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityScheduleFacade;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
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
 *
 * Расходы диспатчатся окном последних дней, а не только за сегодня:
 * начисление приходит позже продажи, иногда на недели, и день,
 * загруженный один раз, назавтра уже неполон. Окно узкое (EXPENSE_WINDOW_DAYS)
 * — это дневной хвост, а не глубокий рескан ADR-006: тот приедет
 * отдельной задачей и с другим ритмом.
 *
 * Каталог диспатчится в том же тике и с тем же ритмом, что продажи.
 * Отдельного расписания у него нет намеренно: разные интервалы — это
 * состояние «когда каталог синхронизировался в прошлый раз», которое
 * пришлось бы где-то держать, а выигрыш — несколько запросов в час
 * при лимитах площадки на порядки выше. Появится подключение с десятками
 * тысяч товаров — разведём, и тогда состояние окупится.
 */
final readonly class DispatchActiveOzonSyncsAction
{
    private const string TIMEZONE = 'Europe/Moscow';

    /**
     * Сколько последних дней перезагружать по расходам каждым тиком.
     * Три — потому что начисления за день продолжают приходить ещё сутки
     * и часть приходит на третьи; больше окно — линейно больше запросов
     * (день = запрос), и хвост длиннее суток закрывает глубокий рескан,
     * а не тик планировщика.
     *
     * ponytail: фиксированные три дня, а не «пока не сойдётся с итогами»
     * — сверка с /v3/finance/transaction/totals появится вместе с экраном,
     * и вот тогда окно станет считаться, а не назначаться.
     */
    private const int EXPENSE_WINDOW_DAYS = 3;

    public function __construct(
        private IdentityScheduleFacade $identitySchedule,
        private MessageBusInterface $bus,
    ) {
    }

    public function __invoke(): int
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $businessDate = $today->format('Y-m-d');
        $targets = $this->identitySchedule->findActiveOzonSyncTargets();

        foreach ($targets as $target) {
            $this->bus->dispatch(new FetchOzonPostingsMessage(
                companyId: $target->companyId,
                marketplaceAccountId: $target->marketplaceAccountId,
                businessDate: $businessDate,
            ));
            $this->bus->dispatch(new FetchOzonCatalogMessage(
                companyId: $target->companyId,
                marketplaceAccountId: $target->marketplaceAccountId,
            ));

            for ($daysAgo = 0; $daysAgo < self::EXPENSE_WINDOW_DAYS; ++$daysAgo) {
                $this->bus->dispatch(new FetchOzonExpensesMessage(
                    companyId: $target->companyId,
                    marketplaceAccountId: $target->marketplaceAccountId,
                    accrualDate: $today->modify("-{$daysAgo} day")->format('Y-m-d'),
                ));
            }
        }

        // Число подключений, а не сообщений: тик планировщика меряется
        // тем, сколько кабинетов он обошёл, и это число не должно
        // меняться от того, что у подключения появилась вторая задача.
        return \count($targets);
    }
}
