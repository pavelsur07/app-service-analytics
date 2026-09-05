<?php

declare(strict_types=1);

namespace App\Tests\Functional\PriceMonitoring;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceListingPriceRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingWriter;
use App\PriceMonitoring\Domain\PriceObservationRepository;
use App\PriceMonitoring\Domain\TrackedSkuRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\MarketplaceListingPriceBuilder;
use App\Tests\Support\Builder\PriceObservationBuilder;
use App\Tests\Support\Builder\TrackedSkuBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Экран СПП (ADR-014, ADR-016). Обязательное покрытие ADR-005: изоляция
 * между компаниями и денежная арифметика — здесь это разница двух цен,
 * взятых из разных модулей.
 */
final class PriceOverviewControllerTest extends WebTestCase
{
    private const string SKU = '308403988';

    public function testCoInvestmentIsTheDifferenceOfCabinetAndShelfPrice(): void
    {
        $client = static::createClient();
        $fixture = $this->company();

        // Числа из спайка по живой карточке: 2537 в кабинете,
        // 1117 на витрине, соинвест 1420.
        $this->track($fixture, self::SKU);
        $this->cabinetPrice($fixture, self::SKU, 253_700, '2026-08-18 08:00:00');
        $this->observation($fixture, self::SKU, 111_700, '2026-08-18 09:00:00');

        $item = $this->overview($client, $fixture)[0];

        self::assertSame(self::SKU, $item['marketplaceSku']);
        self::assertSame(253_700, $item['sellerPriceMinor']);
        self::assertSame(111_700, $item['displayedPriceMinor']);
        self::assertSame(142_000, $item['coInvestmentMinor']);
        self::assertSame('RUB', $item['currency']);
    }

    /**
     * То, ради чего заводилась история цен (ADR-015): наблюдение
     * сравнивается с ценой, действовавшей тогда, а не с сегодняшней.
     */
    public function testCabinetPriceIsTakenAtTheMomentOfObservation(): void
    {
        $client = static::createClient();
        $fixture = $this->company();

        $this->track($fixture, self::SKU);
        $this->cabinetPrice($fixture, self::SKU, 253_700, '2026-08-18 08:00:00');
        $this->observation($fixture, self::SKU, 111_700, '2026-08-18 09:00:00');
        // Продавец поднял цену уже после снимка — на старое наблюдение
        // это влиять не должно.
        $this->cabinetPrice($fixture, self::SKU, 400_000, '2026-08-18 10:00:00');

        $item = $this->overview($client, $fixture)[0];

        self::assertSame(253_700, $item['sellerPriceMinor'], 'взята цена на момент снимка, а не последняя');
        self::assertSame(142_000, $item['coInvestmentMinor']);
    }

    public function testTrackedSkuWithoutObservationsIsShownWithEmptyPrices(): void
    {
        $client = static::createClient();
        $fixture = $this->company();

        $this->track($fixture, self::SKU);
        $this->cabinetPrice($fixture, self::SKU, 253_700, '2026-08-18 08:00:00');

        $item = $this->overview($client, $fixture)[0];

        // Убрать такую строку с экрана значило бы соврать, что артикул
        // не отслеживается. Пустые цены — это «расширение сюда ещё
        // не дошло», и состояние обязано быть различимым.
        self::assertSame(self::SKU, $item['marketplaceSku']);
        self::assertNull($item['displayedPriceMinor']);
        self::assertNull($item['coInvestmentMinor']);
        self::assertNull($item['observedAt']);
    }

    public function testObservationOlderThanAnyKnownCabinetPriceHasNoCoInvestment(): void
    {
        $client = static::createClient();
        $fixture = $this->company();

        $this->track($fixture, self::SKU);
        $this->observation($fixture, self::SKU, 111_700, '2026-08-18 09:00:00');
        // История цен начинается позже наблюдения: сравнивать не с чем.
        $this->cabinetPrice($fixture, self::SKU, 253_700, '2026-08-18 10:00:00');

        $item = $this->overview($client, $fixture)[0];

        // Ноль вместо null означал бы, что товар отдавали даром.
        self::assertNull($item['sellerPriceMinor']);
        self::assertNull($item['coInvestmentMinor']);
        self::assertSame(111_700, $item['displayedPriceMinor']);
    }

    public function testNegativeCoInvestmentIsShownAsIs(): void
    {
        $client = static::createClient();
        $fixture = $this->company();

        $this->track($fixture, self::SKU);
        $this->cabinetPrice($fixture, self::SKU, 100_000, '2026-08-18 08:00:00');
        $this->observation($fixture, self::SKU, 150_000, '2026-08-18 09:00:00');

        // Витрина выше кабинета — либо прочитали не тот узел страницы,
        // либо цену подняли между выгрузками. Поджать к нулю значило бы
        // спрятать поломку парсера.
        self::assertSame(-50_000, $this->overview($client, $fixture)[0]['coInvestmentMinor']);
    }

    public function testAnotherCompanyDoesNotLeakIntoTheScreen(): void
    {
        $client = static::createClient();
        $fixture = $this->company();

        // Тот же артикул у чужой компании: артикулы площадки общие
        // для всех продавцов, изоляцию держит company_id в самом SQL.
        $foreign = Uuid::v7();
        TrackedSkuBuilder::aTrackedSku()
            ->withCompanyId($foreign)
            ->withMarketplaceSku(self::SKU)
            ->persistWith($this->trackedSkus());

        self::assertSame([], $this->overview($client, $fixture));
    }

    /**
     * Изоляция именно нового межмодульного чтения (ADR-016): своя строка
     * есть, значит запрос к каталогу действительно выполняется — и он
     * обязан взять цену своей компании, а не чужой с тем же артикулом.
     */
    public function testForeignCabinetPriceDoesNotLeakIntoOurCoInvestment(): void
    {
        $client = static::createClient();
        $fixture = $this->company();

        $this->track($fixture, self::SKU);
        $this->cabinetPrice($fixture, self::SKU, 253_700, '2026-08-18 08:00:00');
        $this->observation($fixture, self::SKU, 111_700, '2026-08-18 09:00:00');

        $foreign = $this->company('foreign@example.com');
        $this->track($foreign, self::SKU);
        $this->cabinetPrice($foreign, self::SKU, 900_000, '2026-08-18 08:30:00');

        $item = $this->overview($client, $fixture)[0];

        self::assertSame(253_700, $item['sellerPriceMinor'], 'цена своей компании, не чужой');
        self::assertSame(142_000, $item['coInvestmentMinor']);
    }

    /**
     * Кабинет участвует в отборе наравне с артикулом. После
     * переподключения магазина в истории остаются строки обоих
     * кабинетов, и выбор без кабинета дал бы правдоподобный,
     * но неверный соинвест — от настоящего его не отличить.
     */
    public function testPriceOfAnotherCabinetOfTheSameCompanyIsNotTaken(): void
    {
        $client = static::createClient();
        $fixture = $this->company();

        $this->track($fixture, self::SKU);
        $this->cabinetPrice($fixture, self::SKU, 253_700, '2026-08-18 08:00:00');
        $this->observation($fixture, self::SKU, 111_700, '2026-08-18 09:00:00');

        // Прежний кабинет той же компании с той же карточкой и более
        // свежей ценой: без кабинета в условии выборка взяла бы её.
        /** @var MarketplaceListingPriceRepository $prices */
        $prices = static::getContainer()->get(MarketplaceListingPriceRepository::class);
        $prices->recordChanged($fixture->company->id()->toRfc4122(), [
            MarketplaceListingPriceBuilder::aMarketplaceListingPrice()
                ->withCompanyId($fixture->company->id())
                ->withMarketplaceAccountId(Uuid::v7())
                ->withMarketplaceSku(self::SKU)
                ->withChangedAt(new \DateTimeImmutable('2026-08-18 08:45:00'))
                ->withPrice(Money::ofMinor(900_000, 'RUB'))
                ->build(),
        ]);

        self::assertSame(253_700, $this->overview($client, $fixture)[0]['sellerPriceMinor']);
    }

    public function testInvalidLimitIsRejected(): void
    {
        $client = static::createClient();
        $fixture = $this->company();

        $this->request($client, $fixture, '?limit=201');

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function overview(KernelBrowser $client, CompanyFixture $fixture): array
    {
        $this->request($client, $fixture);
        self::assertResponseIsSuccessful();
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{items: list<array<string, mixed>>} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload['items'];
    }

    private function request(KernelBrowser $client, CompanyFixture $fixture, string $query = ''): void
    {
        $client->loginUser($fixture->user, 'api');
        $client->request('GET', '/api/companies/'.$fixture->company->id()->toRfc4122().'/prices'.$query);
    }

    private function track(CompanyFixture $fixture, string $sku): void
    {
        TrackedSkuBuilder::aTrackedSku()
            ->withCompany($fixture->company)
            ->withMarketplaceAccount($fixture->account)
            ->withMarketplaceSku($sku)
            ->persistWith($this->trackedSkus());

        $this->listings()->replaceForAccount(
            $fixture->company->id()->toRfc4122(),
            $fixture->account->id(),
            [
                MarketplaceListingBuilder::aMarketplaceListing()
                    ->withCompanyId($fixture->company->id())
                    ->withMarketplaceAccountId($fixture->account->id())
                    ->withMarketplaceSku($sku)
                    ->withSeenAt(new \DateTimeImmutable())
                    ->build(),
            ],
        );
    }

    private function cabinetPrice(CompanyFixture $fixture, string $sku, int $minor, string $at): void
    {
        /** @var MarketplaceListingPriceRepository $prices */
        $prices = static::getContainer()->get(MarketplaceListingPriceRepository::class);
        $prices->recordChanged($fixture->company->id()->toRfc4122(), [
            MarketplaceListingPriceBuilder::aMarketplaceListingPrice()
                ->withCompanyId($fixture->company->id())
                ->withMarketplaceAccountId($fixture->account->id())
                ->withMarketplaceSku($sku)
                ->withChangedAt(new \DateTimeImmutable($at))
                ->withPrice(Money::ofMinor($minor, 'RUB'))
                ->build(),
        ]);
    }

    private function observation(CompanyFixture $fixture, string $sku, int $minor, string $at): void
    {
        /** @var PriceObservationRepository $observations */
        $observations = static::getContainer()->get(PriceObservationRepository::class);
        PriceObservationBuilder::aPriceObservation()
            ->withCompany($fixture->company)
            ->withMarketplaceAccount($fixture->account)
            ->withMarketplaceSku($sku)
            ->withObservedAt(new \DateTimeImmutable($at))
            ->withDisplayedPrice(Money::ofMinor($minor, 'RUB'))
            ->persistWith($observations);
    }

    private function trackedSkus(): TrackedSkuRepository
    {
        /** @var TrackedSkuRepository $repository */
        $repository = static::getContainer()->get(TrackedSkuRepository::class);

        return $repository;
    }

    private function listings(): MarketplaceListingRepository
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return new DoctrineMarketplaceListingWriter($connection);
    }

    private function company(string $email = 'owner@example.com'): CompanyFixture
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);
        $users = new DoctrineUserRepository($entityManager);
        $members = new DoctrineCompanyMemberRepository($entityManager);

        $company = CompanyBuilder::aCompany()->withName('Acme LLC')->persistWith($companies);
        $user = UserBuilder::aUser()->withEmail($email)->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $members);
        // externalShopId по email: несколько компаний в одном тесте требуют
        // разных кабинетов — глобальный индекс (marketplace, external_shop_id)
        // теперь не пускает два одинаковых id в разные компании (ADR-021).
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)->withExternalShopId('shop-'.$email)->persistWith($companies, $accounts);

        return new CompanyFixture($company, $user, $account);
    }
}

final readonly class CompanyFixture
{
    public function __construct(
        public Company $company,
        public User $user,
        public MarketplaceAccount $account,
    ) {
    }
}
