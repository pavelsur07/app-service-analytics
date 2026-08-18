<?php

declare(strict_types=1);

namespace App\Tests\Functional\PriceMonitoring;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineExtensionTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\PriceMonitoring\Domain\PriceObservation;
use App\PriceMonitoring\Domain\TrackedSkuRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\ExtensionTokenBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\TrackedSkuBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Приём наблюдений цены (ADR-014). Обязательное покрытие ADR-005:
 * идемпотентность приёма и изоляция данных между компаниями.
 */
final class PriceObservationControllerTest extends WebTestCase
{
    private const string OBSERVED_AT = '2026-08-17T09:00:00Z';

    public function testTheSameObservationSentTwiceCreatesOneRow(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        $this->post($client, $fixture);
        self::assertResponseStatusCodeSame(200);
        // Повтор после сетевого сбоя обязан быть неотличим от первой
        // удачной отправки: расширение не знает, доехал ли ответ.
        $this->post($client, $fixture);
        self::assertResponseStatusCodeSame(200);

        self::assertSame(1, $this->countObservations($fixture));
    }

    public function testADifferentMomentIsADifferentObservation(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        $this->post($client, $fixture);
        $this->post($client, $fixture, observedAt: '2026-08-17T09:30:00Z');

        self::assertSame(2, $this->countObservations($fixture));
    }

    public function testObservationForANotTrackedSkuIsRejected(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        // Эндпоинт принимает снимки только по отслеживаемым артикулам:
        // иначе это место для произвольной записи чем попало.
        $this->post($client, $fixture, marketplaceSku: '999999999');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('tracked_sku_not_found', $this->payload($client)['code']);
        self::assertSame(0, $this->countObservations($fixture));

        // Предупреждением, не ошибкой (ADR-014). Проверяется само наличие
        // сигнала: между открытием фонового окна и отправкой снимка
        // продавец мог нажать «Остановить», и молча проглоченный отказ
        // неотличим от того, что расширение просто ничего не прислало.
        /** @var TestHandler $handler */
        $handler = static::getContainer()->get('monolog.handler.in_memory');
        self::assertTrue(
            $handler->hasWarningThatContains('неотслеживаемому артикулу'),
            'приём по неотслеживаемому артикулу обязан оставить предупреждение в журнале',
        );
    }

    public function testObservationForAStoppedSkuIsRejected(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        $this->trackedSkus()->stopIfActive(
            $fixture->company->id()->toRfc4122(),
            '100000001',
            new \DateTimeImmutable(),
        );

        $this->post($client, $fixture);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countObservations($fixture));
    }

    public function testSkuTrackedByAnotherCompanyDoesNotOpenTheEndpoint(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        // Тот же артикул отслеживает чужая компания: артикулы площадки
        // общие для всех продавцов, и право прислать снимок обязано
        // проверяться по company_id в самом SQL, а не по факту, что
        // артикул кем-то отслеживается.
        TrackedSkuBuilder::aTrackedSku()
            ->withCompanyId(Uuid::v7())
            ->withMarketplaceSku('777777777')
            ->persistWith($this->trackedSkus());

        $this->post($client, $fixture, marketplaceSku: '777777777');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, $this->countObservations($fixture));
    }

    public function testTokenOfAnotherCompanyIsRejectedEvenForItsOwnMember(): void
    {
        $client = static::createClient();
        [$companies, $users, $members, $tokens] = $this->repositories();

        $first = CompanyBuilder::aCompany()->withName('First')->persistWith($companies);
        $second = CompanyBuilder::aCompany()->withName('Second')->persistWith($companies);
        $user = UserBuilder::aUser()->withEmail('member@example.com')->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($first)->withUser($user)->persistWith($companies, $users, $members);
        CompanyMemberBuilder::aCompanyMember()->withCompany($second)->withUser($user)->persistWith($companies, $users, $members);

        $secret = ExtensionTokenSecret::generate();
        ExtensionTokenBuilder::anExtensionToken()
            ->withCompany($first)
            ->withUser($user)
            ->withSecret($secret)
            ->persistWith($companies, $users, $tokens);

        $client->request(
            'POST',
            '/api/extension/companies/'.$second->id()->toRfc4122().'/price-observations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret->plaintext()],
            content: $this->body(),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testWithoutTokenTheEndpointIsUnauthorized(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        $client->request(
            'POST',
            '/api/extension/companies/'.$fixture->company->id()->toRfc4122().'/price-observations',
            content: $this->body(),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheObservationLandsOnTheTrackedAccountWithBothPrices(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        $this->post($client, $fixture);

        $row = $this->observationRow($fixture);
        // Кабинет берётся из строки отслеживания, а не из тела запроса:
        // расширение его не знает и знать не должно.
        self::assertSame($fixture->account->id()->toRfc4122(), $row['marketplace_account_id']);
        self::assertSame(111_700, self::intColumn($row, 'displayed_price_minor'));
        self::assertSame('RUB', $row['currency']);
        self::assertSame(PriceObservation::SourceExtension, $row['source']);
        self::assertSame($fixture->user->id()->toRfc4122(), $row['captured_by_user_id']);
        self::assertSame('0.1.0', $row['extension_version']);
    }

    /**
     * Цена пишется как есть, без округлений и поджатий. СПП считается
     * при чтении разницей с ценой продавца из каталога (ADR-015),
     * и любое «исправление» на входе потеряло бы признак того, что
     * страницу разобрали неверно.
     */
    public function testPriceIsStoredExactlyAsGiven(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        $this->post($client, $fixture, displayedMinor: 100_501);

        self::assertSame(100_501, self::intColumn($this->observationRow($fixture), 'displayed_price_minor'));
    }

    public function testMalformedInputIsRejected(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        $this->post($client, $fixture, observedAt: 'вчера');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('observed_at_invalid', $this->payload($client)['code']);

        // Дробная сумма — та самая граница, где ADR-004 нарушается
        // незаметно: JSON-число это double.
        $this->postRaw($client, $fixture, json_encode([
            'marketplaceSku' => '100000001',
            'observedAt' => self::OBSERVED_AT,
            'displayedPrice' => ['amount' => 1299.99, 'currency' => 'RUB'],
            'extensionVersion' => '0.1.0',
        ], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('displayed_price_required', $this->payload($client)['code']);

        $this->postRaw($client, $fixture, 'not json');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('malformed_json', $this->payload($client)['code']);

        self::assertSame(0, $this->countObservations($fixture));
    }

    public function testRateLimitStopsARunawayExtension(): void
    {
        $client = static::createClient();
        $fixture = $this->trackingCompany();

        // Порог в тестовом окружении занижен до 5
        // (config/packages/rate_limiter.yaml, when@test). Ключ лимитера —
        // компания, а она у каждого теста своя.
        $minute = 0;
        for ($i = 0; $i < 5; ++$i) {
            $this->post($client, $fixture, observedAt: \sprintf('2026-08-17T09:%02d:00Z', $minute++));
            self::assertResponseStatusCodeSame(200);
        }

        $this->post($client, $fixture, observedAt: \sprintf('2026-08-17T09:%02d:00Z', $minute));
        self::assertResponseStatusCodeSame(429);
        self::assertSame('too_many_observations', $this->payload($client)['code']);
        self::assertNotNull($client->getResponse()->headers->get('Retry-After'));
    }

    private function post(
        KernelBrowser $client,
        TrackingCompany $fixture,
        string $marketplaceSku = '100000001',
        string $observedAt = self::OBSERVED_AT,
        int $displayedMinor = 111_700,
    ): void {
        $this->postRaw($client, $fixture, $this->body($marketplaceSku, $observedAt, $displayedMinor));
    }

    private function postRaw(KernelBrowser $client, TrackingCompany $fixture, string $body): void
    {
        $client->request(
            'POST',
            '/api/extension/companies/'.$fixture->company->id()->toRfc4122().'/price-observations',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture->secret->plaintext()],
            content: $body,
        );
    }

    private function body(
        string $marketplaceSku = '100000001',
        string $observedAt = self::OBSERVED_AT,
        int $displayedMinor = 111_700,
    ): string {
        return json_encode([
            'marketplaceSku' => $marketplaceSku,
            'observedAt' => $observedAt,
            'displayedPrice' => ['amount' => $displayedMinor, 'currency' => 'RUB'],
            'extensionVersion' => '0.1.0',
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function countObservations(TrackingCompany $fixture): int
    {
        $count = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM price_observation WHERE company_id = :companyId',
            ['companyId' => $fixture->company->id()->toRfc4122()],
        );
        self::assertTrue(\is_int($count) || \is_string($count));

        return (int) $count;
    }

    /**
     * Postgres отдаёт bigint строкой, а fetchAssociative типизирован
     * как mixed — сужение в одном месте вместо приведения на каждой
     * проверке.
     *
     * @param array<string, mixed> $row
     */
    private static function intColumn(array $row, string $column): int
    {
        $value = $row[$column] ?? null;
        self::assertTrue(\is_int($value) || \is_string($value));

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function observationRow(TrackingCompany $fixture): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT * FROM price_observation WHERE company_id = :companyId',
            ['companyId' => $fixture->company->id()->toRfc4122()],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }

    private function trackedSkus(): TrackedSkuRepository
    {
        /** @var TrackedSkuRepository $repository */
        $repository = static::getContainer()->get(TrackedSkuRepository::class);

        return $repository;
    }

    private function trackingCompany(): TrackingCompany
    {
        [$companies, $users, $members, $tokens] = $this->repositories();

        $company = CompanyBuilder::aCompany()->withName('Acme LLC')->persistWith($companies);
        $user = UserBuilder::aUser()->withEmail('owner@example.com')->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $members);

        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->persistWith($companies, $accounts);

        TrackedSkuBuilder::aTrackedSku()
            ->withCompany($company)
            ->withMarketplaceAccount($account)
            ->withMarketplaceSku('100000001')
            ->withCreatedBy($user)
            ->persistWith($this->trackedSkus());

        $secret = ExtensionTokenSecret::generate();
        ExtensionTokenBuilder::anExtensionToken()
            ->withCompany($company)
            ->withUser($user)
            ->withSecret($secret)
            ->persistWith($companies, $users, $tokens);

        return new TrackingCompany($company, $user, $account, $secret);
    }

    /**
     * @return array{0: CompanyRepository, 1: DoctrineUserRepository, 2: DoctrineCompanyMemberRepository, 3: DoctrineExtensionTokenRepository}
     */
    private function repositories(): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return [
            $companies,
            new DoctrineUserRepository($entityManager),
            new DoctrineCompanyMemberRepository($entityManager),
            new DoctrineExtensionTokenRepository($entityManager),
        ];
    }
}

final readonly class TrackingCompany
{
    public function __construct(
        public Company $company,
        public User $user,
        public MarketplaceAccount $account,
        public ExtensionTokenSecret $secret,
    ) {
    }
}
