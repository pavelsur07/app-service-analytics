<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Application\Facade\IdentityScheduleFacade;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Ingestion\Application\DispatchActiveOzonSyncsAction;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Application\Message\FetchOzonReturnsMessage;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Скользящее окно продаж и суточный глубокий рескан (ADR-006).
 *
 * Предмет проверки не «сколько запросов», а свойство продукта: заказ,
 * загруженный в день создания, лежит со статусом «собирается» и сам
 * на «доставлен» не сменится — площадка меняет его молча. Пока
 * спрашивали только сегодня, из 869 заказов, загруженных с 9 августа
 * 2026, доставленным не значился ни один, и экран экономики показывал
 * 281 тысячу рублей выручки вместо 2,2 миллиона.
 *
 * Час рескана задаётся конструктором явно, а не берётся из умолчания:
 * иначе тест проходил бы двадцать три часа в сутки и падал в один.
 */
final class PostingsWindowTest extends KernelTestCase
{
    private const string TIMEZONE = 'Europe/Moscow';

    protected function setUp(): void
    {
        parent::setUp();

        // Одно активное подключение: предмет теста — окно дат, а не обход
        // нескольких кабинетов, и второе подключение лишь удвоило бы
        // список, ничего не проверив.
        $companies = $this->container()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $accounts = $this->container()->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-window')
            ->persistWith($companies, $accounts);
    }

    public function testOrdinaryTickAsksForTheWholeWindowNotJustToday(): void
    {
        $dates = $this->postingDates($this->actionWithRescanAt($this->hourThatIsNotNow()));

        self::assertSame($this->days(3), $dates);
    }

    public function testRescanTickReachesBackFarEnoughForSlowDeliveries(): void
    {
        $dates = $this->postingDates($this->actionWithRescanAt($this->hourNow()));

        // Заказ едет в Хабаровск или Красноярск неделями; трёх дней
        // на такой хвост не хватает, и его закрывает рескан.
        self::assertSame($this->days(30), $dates);
        self::assertContains(
            (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))
                ->modify('-14 day')->format('Y-m-d'),
            $dates,
        );
    }

    public function testExpenseWindowIsNotAffectedByTheRescan(): void
    {
        $action = $this->actionWithRescanAt($this->hourNow());
        ($action)();

        $expenseDates = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchOzonExpensesMessage) {
                $expenseDates[] = $message->accrualDate;
            }
        }

        // У расходов свой ритм: начисление приходит за дни, а не за
        // недели, и раздувать его вместе с продажами незачем.
        self::assertSame($this->days(3), $expenseDates);
    }

    public function testOrdinaryTickDispatchesOneThreeDayReturnsWindow(): void
    {
        $ranges = $this->returnRanges($this->actionWithRescanAt($this->hourThatIsNotNow()));

        self::assertSame([[$this->days(3)[2], $this->days(3)[0]]], $ranges);
    }

    public function testRescanTickDispatchesOneNinetyDayReturnsWindow(): void
    {
        $ranges = $this->returnRanges($this->actionWithRescanAt($this->hourNow()));

        self::assertSame([[$this->days(90)[89], $this->days(90)[0]]], $ranges);
    }

    /**
     * @return list<string>
     */
    private function postingDates(DispatchActiveOzonSyncsAction $action): array
    {
        ($action)();

        $dates = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchOzonPostingsMessage) {
                $dates[] = $message->businessDate;
            }
        }

        return $dates;
    }

    /**
     * @return list<array{string, string}>
     */
    private function returnRanges(DispatchActiveOzonSyncsAction $action): array
    {
        ($action)();

        $ranges = [];
        foreach ($this->transport()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof FetchOzonReturnsMessage) {
                $ranges[] = [$message->from, $message->to];
            }
        }

        return $ranges;
    }

    /**
     * @return list<string>
     */
    private function days(int $count): array
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));

        $days = [];
        for ($daysAgo = 0; $daysAgo < $count; ++$daysAgo) {
            $days[] = $today->modify("-{$daysAgo} day")->format('Y-m-d');
        }

        return $days;
    }

    private function actionWithRescanAt(int $hour): DispatchActiveOzonSyncsAction
    {
        /** @var IdentityScheduleFacade $schedule */
        $schedule = $this->container()->get(IdentityScheduleFacade::class);

        return new DispatchActiveOzonSyncsAction(
            identitySchedule: $schedule,
            bus: $this->bus(),
            rescanHour: $hour,
        );
    }

    private function hourNow(): int
    {
        return (int) (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('G');
    }

    private function hourThatIsNotNow(): int
    {
        return ($this->hourNow() + 12) % 24;
    }

    private function bus(): \Symfony\Component\Messenger\MessageBusInterface
    {
        /** @var \Symfony\Component\Messenger\MessageBusInterface $bus */
        $bus = $this->container()->get(\Symfony\Component\Messenger\MessageBusInterface::class);

        return $bus;
    }

    private function transport(): InMemoryTransport
    {
        $transport = $this->container()->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function container(): ContainerInterface
    {
        if (!self::$booted) {
            self::bootKernel();
        }

        return self::getContainer();
    }
}
