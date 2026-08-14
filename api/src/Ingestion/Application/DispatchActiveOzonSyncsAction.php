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
 * загруженный один раз, назавтра уже неполон. Окно узкое — это дневной
 * хвост, а не глубокий рескан ADR-006: тот приедет отдельной задачей
 * и с другим ритмом.
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
     * Окно пересчёта расходов настраивается (ADR-006: «оба параметра
     * конфигурируемы»), умолчание — три дня.
     *
     * Три, а не сорок пять из ADR-006, и это осознанное расхождение:
     * там окно написано для источников, где период стоит одного запроса,
     * а здесь день — это запрос, и сорок пять дней на каждом тике
     * планировщика означали бы сорок пять запросов каждые полчаса.
     * Начисления за день приходят ещё сутки, часть — на третьи; длинный
     * хвост закрывает глубокий рескан из того же ADR-006, у которого
     * будет свой ритм, а не тик.
     *
     * ponytail: окно назначено, а не вычислено. Сверка с итогами периода
     * (/v3/finance/transaction/totals) появится вместе с экраном —
     * и тогда окно станет считаться от расхождения, а не от догадки.
     */
    public function __construct(
        private IdentityScheduleFacade $identitySchedule,
        private MessageBusInterface $bus,
        private int $expenseWindowDays = 3,
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

            for ($daysAgo = 0; $daysAgo < $this->expenseWindowDays; ++$daysAgo) {
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
