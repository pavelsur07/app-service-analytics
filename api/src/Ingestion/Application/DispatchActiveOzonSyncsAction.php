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
 * Продажи диспатчатся скользящим окном плюс суточным глубоким рескном
 * (ADR-006). Одного «сегодня» было мало, и цена этого измерена:
 * на 16 августа 2026 из 869 заказов, загруженных с 9-го числа, ни один
 * не значился доставленным — статус застывал таким, каким был в день
 * загрузки. Экран экономики считает выручкой доставленное и показывал
 * 281 тысячу рублей вместо 2,2 миллиона.
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
    /**
     * Окно продаж — тот же приём и та же причина, что у расходов, но
     * хвост длиннее: начисление приходит за дни, а заказ едет неделями.
     * Три дня ловят смену статуса у свежих заказов, глубокий рескан —
     * тех, кто доехал до Хабаровска на второй неделе.
     *
     * Рескан суточный, а не квартальный, как в ADR-006: там речь про
     * перевыпуск отчётов площадкой, здесь — про доставку, у которой
     * весь срок укладывается в месяц. Тридцать дней раз в сутки стоят
     * тридцати запросов; при нашем расходе в 0,2 RPS против лимита
     * в 50 это незаметно.
     *
     * Расхождение с ADR-006 названо прямо, чтобы не выглядело недосмотром.
     * Там окно по умолчанию 45 дней, рескан квартальный и включается
     * «после накопления истории глубже квартала». Числа ADR разрешает
     * настраивать («оба параметра конфигурируемы»), а вот условие
     * включения мы нарушаем сознательно: истории две недели, а рескан
     * уже работает. Ждать квартала значило бы три месяца показывать
     * заниженную выручку ради экономии тридцати запросов в сутки при
     * лимите площадки в 50 запросов в секунду.
     *
     * ponytail: тридцать дней — назначенное число, как и три у расходов.
     * Сверка с кабинетом покажет, хватает ли; пока это осознанная
     * догадка, а не расчёт.
     */
    public function __construct(
        private IdentityScheduleFacade $identitySchedule,
        private MessageBusInterface $bus,
        private int $expenseWindowDays = 3,
        private int $postingWindowDays = 3,
        private int $postingRescanDays = 30,
        private int $rescanHour = 3,
    ) {
    }

    public function __invoke(): int
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $targets = $this->identitySchedule->findActiveOzonSyncTargets();

        $postingDays = $this->isRescanTick($today) ? $this->postingRescanDays : $this->postingWindowDays;

        foreach ($targets as $target) {
            // Окно, а не только сегодня: заказ, загруженный в день
            // создания, лежит у нас со статусом «собирается» и сам
            // на «доставлен» не сменится — площадка меняет его молча,
            // и узнать об этом можно только переспросив.
            for ($daysAgo = 0; $daysAgo < $postingDays; ++$daysAgo) {
                $this->bus->dispatch(new FetchOzonPostingsMessage(
                    companyId: $target->companyId,
                    marketplaceAccountId: $target->marketplaceAccountId,
                    businessDate: $today->modify("-{$daysAgo} day")->format('Y-m-d'),
                ));
            }
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

    /**
     * Глубокий рескан — раз в сутки, в тихий час.
     *
     * Признак — час бизнес-даты, а не «когда сканировали в прошлый раз».
     * Хранимая отметка потребовала бы таблицы и умела бы разъезжаться
     * с реальностью при перезапуске; час же не требует ничего и не врёт.
     *
     * Тик идёт каждые полчаса, значит в любой час попадает хотя бы один —
     * рескан не пропускается, даже если выкладка сдвинула фазу
     * планировщика (а она сдвигает: 16 августа выкладка перевела тики
     * с :11/:41 на :26/:56). Попасть их может и два — это лишние
     * тридцать запросов в сутки и ничего больше: загрузка идемпотентна.
     */
    private function isRescanTick(\DateTimeImmutable $now): bool
    {
        return (int) $now->format('G') === $this->rescanHour;
    }
}
