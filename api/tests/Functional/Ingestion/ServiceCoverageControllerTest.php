<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Persistence\DbalServiceCoverageCaptureStore;
use App\Ingestion\Infrastructure\ServiceCoverage\OzonFetcherAllowlist;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageState;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use App\Tests\Support\Builder\ServiceCoverageCaptureBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Экран «Покрытие GO» — межарендаторное чтение системного контура
 * (CLAUDE.md §1, ADR-018). Через HTTP проверяется обязательное покрытие
 * изоляции: загрузка PHP считается только по кабинетам allowlist сервиса,
 * а в ответ не попадают ни компании, ни кабинеты, ни токен сервиса;
 * экран закрыт для анонима и сессии продавца. И намеренный сигнал
 * наружу: неудачная попытка и «ещё не снимали» — состояния, а не пустая
 * сводка (§9). Сервис экран не вызывает — читает сохранённую сводку.
 */
final class ServiceCoverageControllerTest extends WebTestCase
{
    private const string URL = '/api/admin/services/ozon-fetcher/coverage';

    public function testAdminSeesServiceNextToPhpLoadsOfAllowlistOnly(): void
    {
        $client = $this->adminClient();
        [$yesterday, $twoDaysAgo, $threeDaysAgo] = [$this->day(-1), $this->day(-2), $this->day(-3)];

        $company = Uuid::v7();
        $allowed = Uuid::v7();
        $foreignCompany = Uuid::v7();
        $foreign = Uuid::v7();
        $load = MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId($company)->withMarketplaceAccountId($allowed);
        $load->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod(new \DateTimeImmutable($yesterday))->persistWith($this->dayLoads());
        // Отказавший день без успеха — не загружен.
        $load->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod(new \DateTimeImmutable($twoDaysAgo))->failed()->persistWith($this->dayLoads());
        // День, который загрузил только PHP.
        $load->withReportType(MarketplaceReportType::OzonAccrualByDay)->withPeriod(new \DateTimeImmutable($threeDaysAgo))->persistWith($this->dayLoads());
        // Кабинет вне allowlist чужой компании — в счёт не идёт.
        MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withCompanyId($foreignCompany)->withMarketplaceAccountId($foreign)
            ->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod(new \DateTimeImmutable($yesterday))->persistWith($this->dayLoads());

        $this->allow($client, $allowed->toRfc4122());
        $this->capture(self::coverage([
            self::row('ozon.orders.fbo', $yesterday, done: 1),
            self::row('ozon.orders.fbo', $twoDaysAgo, done: 1),
            self::row('ozon.ads.expense', $yesterday, done: 1),
            self::row('ozon.products.list', 'ref', done: 1),
            // Сводка снята за 93 дня — в окно 31 день не попадает.
            self::row('ozon.orders.fbo', $this->day(-40), done: 1),
        ]));

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        $payload = $this->decode($body);
        self::assertSame('ok', $payload['serviceState']);
        self::assertTrue($payload['phpCompared']);
        self::assertSame(1, $payload['cabinetsSeller']);
        self::assertSame(1, $payload['cabinetsPerformance']);
        self::assertSame($this->day(-30), $payload['from']);
        self::assertSame($this->day(0), $payload['to']);
        self::assertSame('2026-10-25T09:00:00+00:00', $payload['serviceGeneratedAt']);
        self::assertIsString($payload['attemptedAt']);

        $rows = $this->rowsByKey($payload);
        self::assertSame(1, $rows['ozon.orders.fbo|'.$yesterday]['phpCabinets'], 'кабинет вне allowlist не считается');
        self::assertSame(0, $rows['ozon.orders.fbo|'.$twoDaysAgo]['phpCabinets'], 'отказ без успеха — не загружен');
        self::assertSame(0, $rows['ozon.ads.expense|'.$yesterday]['phpCabinets']);
        self::assertNull($rows['ozon.products.list|ref']['phpCabinets'], 'у справочника нет пары в PHP');
        self::assertNull($rows['ozon.finance.accrual-by-day|'.$threeDaysAgo]['cabinetsExpected'], 'строка только PHP');
        self::assertSame(1, $rows['ozon.finance.accrual-by-day|'.$threeDaysAgo]['phpCabinets']);
        self::assertArrayNotHasKey('ozon.orders.fbo|'.$this->day(-40), $rows, 'строка вне окна');

        foreach ([$company, $allowed, $foreignCompany, $foreign] as $id) {
            self::assertStringNotContainsString($id->toRfc4122(), $body, 'в ответе нет идентификаторов компаний и кабинетов');
        }
    }

    public function testFailedAttemptIsAStateAndLastCaptureStaysVisible(): void
    {
        $client = $this->adminClient();
        $allowed = Uuid::v7();
        MarketplaceDayLoadBuilder::aMarketplaceDayLoad()->withMarketplaceAccountId($allowed)
            ->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod(new \DateTimeImmutable($this->day(-1)))->persistWith($this->dayLoads());
        $this->allow($client, $allowed->toRfc4122());
        $this->capture(self::coverage([self::row('ozon.orders.fbo', $this->day(-2), done: 1)]));
        ServiceCoverageCaptureBuilder::aCapture()->failedAs(ServiceCoverageState::Unavailable)->persistWith($this->captures());

        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $payload = $this->decode((string) $client->getResponse()->getContent());
        self::assertSame('unavailable', $payload['serviceState']);
        self::assertSame('2026-10-25T09:00:00+00:00', $payload['serviceGeneratedAt'], 'последняя удачная сводка остаётся');
        self::assertSame(1, $payload['cabinetsSeller']);
        $rows = $this->rowsByKey($payload);
        self::assertSame(1, $rows['ozon.orders.fbo|'.$this->day(-2)]['done']);
        self::assertSame(1, $rows['ozon.orders.fbo|'.$this->day(-1)]['phpCabinets']);
    }

    public function testNeverCapturedIsAStateNotAnEmptyCoverage(): void
    {
        $client = $this->adminClient();
        $this->allow($client, '');

        $client->request('GET', self::URL);

        $payload = $this->decode((string) $client->getResponse()->getContent());
        self::assertSame('never', $payload['serviceState']);
        self::assertNull($payload['attemptedAt']);
        self::assertNull($payload['serviceGeneratedAt']);
        self::assertSame([], $payload['rows']);
    }

    public function testWithoutAllowlistPhpIsNotCompared(): void
    {
        $client = $this->adminClient();
        MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
            ->withReportType(MarketplaceReportType::OzonPostingFboList)->withPeriod(new \DateTimeImmutable($this->day(-1)))->persistWith($this->dayLoads());
        $this->allow($client, '');
        $this->capture(self::coverage([self::row('ozon.orders.fbo', $this->day(-1), done: 1)]));

        $client->request('GET', self::URL);

        $payload = $this->decode((string) $client->getResponse()->getContent());
        self::assertFalse($payload['phpCompared']);
        $rows = $this->rowsByKey($payload);
        self::assertCount(1, $rows, 'загрузка PHP без allowlist не читается');
        self::assertNull($rows['ozon.orders.fbo|'.$this->day(-1)]['phpCabinets']);
    }

    public function testWindowIsMonthOrContractLimit(): void
    {
        $client = $this->adminClient();
        $this->allow($client, '');

        foreach (['7', '30', '94', 'abc'] as $days) {
            $client->request('GET', self::URL.'?days='.$days);
            self::assertResponseStatusCodeSame(422, $days);
        }

        $client->request('GET', self::URL.'?days=93');
        self::assertResponseIsSuccessful();
        self::assertSame($this->day(-92), $this->decode((string) $client->getResponse()->getContent())['from']);
    }

    public function testAnonymousAndSellerSessionAreRejected(): void
    {
        $client = static::createClient();

        $client->request('GET', self::URL);
        self::assertResponseStatusCodeSame(401);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $user = UserBuilder::aUser()->withEmail('seller-coverage@example.com')->persistWith(new DoctrineUserRepository($entityManager));
        $client->loginUser($user, 'api');

        $client->request('GET', self::URL);
        self::assertResponseStatusCodeSame(401, 'сессия продавца не открывает системный контур');
    }

    private function adminClient(): KernelBrowser
    {
        $client = static::createClient();
        // Подменённый allowlist живёт до перезагрузки ядра.
        $client->disableReboot();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var PasswordHasherFactoryInterface $hashers */
        $hashers = static::getContainer()->get(PasswordHasherFactoryInterface::class);
        $hash = $hashers->getPasswordHasher(AdministratorBuilder::anAdministrator()->build())->hash('admin-password');
        AdministratorBuilder::anAdministrator()
            ->withEmail('coverage@conwix.local')
            ->withPasswordHash($hash)
            ->persistWith(new DoctrineAdministratorRepository($entityManager));

        $client->request(
            'POST',
            '/api/admin/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email' => 'coverage@conwix.local', 'password' => 'admin-password'], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseIsSuccessful();

        return $client;
    }

    private function allow(KernelBrowser $client, string $allowlist): void
    {
        $client->getContainer()->set(OzonFetcherAllowlist::class, new OzonFetcherAllowlist($allowlist));
    }

    private function capture(string $payload): void
    {
        ServiceCoverageCaptureBuilder::aCapture()
            ->withPayload($payload)
            ->withAt(new \DateTimeImmutable('-1 minute'))
            ->persistWith($this->captures());
    }

    private function captures(): DbalServiceCoverageCaptureStore
    {
        /** @var DbalServiceCoverageCaptureStore $store */
        $store = static::getContainer()->get(DbalServiceCoverageCaptureStore::class);

        return $store;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function coverage(array $rows): string
    {
        return json_encode([
            'schema_version' => '1',
            'service' => 'ozon-fetcher',
            'generated_at' => '2026-10-25T09:00:00Z',
            'from' => '2026-09-25',
            'to' => '2026-10-25',
            'cabinets_total' => 1,
            'rows' => $rows,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(string $resourceType, string $periodKey, int $done): array
    {
        return [
            'resource_type' => $resourceType,
            'period_key' => $periodKey,
            'cabinets_expected' => 1,
            'by_status' => ['done' => $done, 'running' => 0, 'pending' => 0, 'not_ready' => 0, 'failed_retryable' => 0, 'failed_permanent' => 0],
            'last_success_at' => '2026-10-25T08:00:00Z',
        ];
    }

    /** День относительно сегодняшнего по Москве, Y-m-d. */
    private function day(int $offset): string
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Europe/Moscow'))->modify(\sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function dayLoads(): MarketplaceDayLoadRepository
    {
        /** @var MarketplaceDayLoadRepository $repository */
        $repository = static::getContainer()->get(MarketplaceDayLoadRepository::class);

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, array<string, mixed>>
     */
    private function rowsByKey(array $payload): array
    {
        self::assertIsArray($payload['rows']);
        $rows = [];
        foreach ($payload['rows'] as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['resourceType']);
            self::assertIsString($row['periodKey']);
            /* @var array<string, mixed> $row */
            $rows[$row['resourceType'].'|'.$row['periodKey']] = $row;
        }

        return $rows;
    }
}
