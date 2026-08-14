<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineExtensionTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingWriter;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\ExtensionTokenBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Чтение данных компании расширением (пакет 3). Обязательное покрытие
 * ADR-005 — изоляция данных между компаниями, и здесь она проверяется
 * дважды: по членству и по области действия токена.
 */
final class ExtensionReadEndpointsTest extends WebTestCase
{
    public function testSkuListReturnsOnlyOwnCompanySkus(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        SalesFactBuilder::aSalesFact()
            ->withCompanyId($fixture->company->id())
            ->withMarketplaceSku('111')
            ->withSourceRowId('own-1')
            ->persistWith($this->salesFacts());
        // Чужая компания с другим артикулом — доказывает изоляцию,
        // а не только то, что свой артикул попадает в ответ.
        SalesFactBuilder::aSalesFact()
            ->withMarketplaceSku('999')
            ->withSourceRowId('foreign-1')
            ->persistWith($this->salesFacts());

        $payload = $this->get($client, $fixture, '/skus');

        self::assertSame(['111'], $payload['items']);
        self::assertNull($payload['nextCursor']);
    }

    public function testSkuListIncludesCatalogItemsWithoutSales(): void
    {
        // Это и есть смысл каталога: товар, который ещё ни разу
        // не продавался, до сих пор считался чужим, и оверлей на его
        // карточке молчал. Для продавца с новинками расширение выглядело
        // как «работает через раз».
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        SalesFactBuilder::aSalesFact()
            ->withCompanyId($fixture->company->id())
            ->withMarketplaceSku('sold-1')
            ->withSourceRowId('sold-row')
            ->persistWith($this->salesFacts());

        $accountId = Uuid::v7();
        $syncedAt = new \DateTimeImmutable();
        $this->listings()->replaceForAccount(
            $fixture->company->id()->toRfc4122(),
            $accountId,
            [
                MarketplaceListingBuilder::aMarketplaceListing()
                    ->withCompanyId($fixture->company->id())
                    ->withMarketplaceAccountId($accountId)
                    ->withMarketplaceSku('never-sold')
                    ->withSeenAt($syncedAt)
                    ->build(),
            ],
        );

        $payload = $this->get($client, $fixture, '/skus');

        // Оба источника, а не один: каталог знает, что есть сейчас,
        // продажи — что было, включая снятое с площадки.
        self::assertSame(['never-sold', 'sold-1'], $payload['items']);
    }

    public function testCatalogOfAnotherCompanyDoesNotLeakIntoSkuList(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        $syncedAt = new \DateTimeImmutable();
        $foreignCompanyId = Uuid::v7();
        $this->listings()->replaceForAccount(
            $foreignCompanyId->toRfc4122(),
            Uuid::v7(),
            [
                MarketplaceListingBuilder::aMarketplaceListing()
                    ->withCompanyId($foreignCompanyId)
                    ->withMarketplaceSku('foreign-sku')
                    ->withSeenAt($syncedAt)
                    ->build(),
            ],
        );

        $payload = $this->get($client, $fixture, '/skus');

        self::assertSame([], $payload['items']);
    }

    public function testTokenOfAnotherCompanyIsRejectedEvenForItsOwnMember(): void
    {
        $client = static::createClient();
        [$companies, $users, $members, $tokens] = $this->repositories();

        // Один человек в двух компаниях: проверки членства мало — она
        // ответит «да» для обеих. Область действия токена проверяет
        // ExtensionTokenScopeSubscriber.
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
            'GET',
            '/api/extension/companies/'.$second->id()->toRfc4122().'/skus',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret->plaintext()],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testTokenIsRejectedForCompanyWithoutMembership(): void
    {
        $client = static::createClient();
        [$companies] = $this->repositories();
        $fixture = $this->connectedCompany();

        $foreign = CompanyBuilder::aCompany()->withName('Foreign')->persistWith($companies);

        $client->request(
            'GET',
            '/api/extension/companies/'.$foreign->id()->toRfc4122().'/skus',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture->secret->plaintext()],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testWithoutTokenTheEndpointIsUnauthorized(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        $client->request('GET', '/api/extension/companies/'.$fixture->company->id()->toRfc4122().'/skus');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSkuListPagesByCursor(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        foreach (['111', '222', '333'] as $index => $sku) {
            SalesFactBuilder::aSalesFact()
                ->withCompanyId($fixture->company->id())
                ->withMarketplaceSku($sku)
                ->withSourceRowId('row-'.$index)
                ->persistWith($this->salesFacts());
        }

        $first = $this->get($client, $fixture, '/skus?limit=2');
        self::assertSame(['111', '222'], $first['items']);
        self::assertSame('222', $first['nextCursor']);

        $second = $this->get($client, $fixture, '/skus?limit=2&cursor=222');
        self::assertSame(['333'], $second['items']);
        self::assertNull($second['nextCursor'], 'последняя страница не предлагает следующую');
    }

    public function testSalesSummarySeparatesCancelledFromOrdered(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();
        $today = new \DateTimeImmutable('today');

        SalesFactBuilder::aSalesFact()
            ->withCompanyId($fixture->company->id())
            ->withMarketplaceSku('111')
            ->withSourceRowId('ordered-1')
            ->withStatus('delivered')
            ->withQuantity(2)
            ->withAmount(Money::ofMinor(216_000, 'RUB'))
            ->withBusinessDate($today)
            ->persistWith($this->salesFacts());
        SalesFactBuilder::aSalesFact()
            ->withCompanyId($fixture->company->id())
            ->withMarketplaceSku('111')
            ->withSourceRowId('cancelled-1')
            ->withStatus('cancelled')
            ->withQuantity(1)
            ->withAmount(Money::ofMinor(108_000, 'RUB'))
            ->withBusinessDate($today)
            ->persistWith($this->salesFacts());
        // Тот же артикул у чужой компании: артикулы площадки общие для
        // всех продавцов, поэтому изоляцию агрегата держит company_id
        // в самом SQL, а не то, что до контроллера дошёл верный токен.
        SalesFactBuilder::aSalesFact()
            ->withMarketplaceSku('111')
            ->withSourceRowId('foreign-1')
            ->withStatus('delivered')
            ->withQuantity(50)
            ->withAmount(Money::ofMinor(9_999_000, 'RUB'))
            ->withBusinessDate($today)
            ->persistWith($this->salesFacts());

        $payload = $this->get($client, $fixture, '/skus/111/sales');

        self::assertSame(30, $payload['days']);
        self::assertIsArray($payload['totals']);
        self::assertCount(1, $payload['totals']);
        $total = $payload['totals'][0];
        self::assertIsArray($total);
        self::assertSame('RUB', $total['currency']);
        // ADR-009: отменённое не вычитается молча из заказанного —
        // обе величины отдаются отдельно, решение за потребителем.
        self::assertSame(2, $total['orderedQuantity']);
        self::assertSame(216_000, $total['orderedAmountMinor']);
        // Доставленное — отдельная категория ADR-009, не свёрнутая
        // с заказанным: здесь оба факта совпали, но статусы разные.
        self::assertSame(2, $total['deliveredQuantity']);
        self::assertSame(216_000, $total['deliveredAmountMinor']);
        self::assertSame(1, $total['cancelledQuantity']);
        self::assertSame(108_000, $total['cancelledAmountMinor']);
    }

    public function testSalesSummaryIgnoresFactsOutsideTheWindow(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        SalesFactBuilder::aSalesFact()
            ->withCompanyId($fixture->company->id())
            ->withMarketplaceSku('111')
            ->withSourceRowId('old-1')
            ->withBusinessDate(new \DateTimeImmutable('-100 days'))
            ->persistWith($this->salesFacts());

        $payload = $this->get($client, $fixture, '/skus/111/sales?days=30');

        // Продаж в окне нет — это пустой итог, а не 404: артикул свой.
        self::assertSame([], $payload['totals']);
    }

    public function testInvalidLimitAndDaysAreRejected(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        $this->get($client, $fixture, '/skus?limit=201');
        self::assertResponseStatusCodeSame(422);

        $this->get($client, $fixture, '/skus/111/sales?days=0');
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(KernelBrowser $client, ConnectedCompany $fixture, string $path): array
    {
        $client->request(
            'GET',
            '/api/extension/companies/'.$fixture->company->id()->toRfc4122().$path,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture->secret->plaintext()],
        );

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function connectedCompany(): ConnectedCompany
    {
        [$companies, $users, $members, $tokens] = $this->repositories();

        $company = CompanyBuilder::aCompany()->withName('Acme LLC')->persistWith($companies);
        $user = UserBuilder::aUser()->withEmail('owner@example.com')->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $members);

        $secret = ExtensionTokenSecret::generate();
        ExtensionTokenBuilder::anExtensionToken()
            ->withCompany($company)
            ->withUser($user)
            ->withSecret($secret)
            ->persistWith($companies, $users, $tokens);

        return new ConnectedCompany($company, $user, $secret);
    }

    /**
     * Строится напрямую, не через контейнер: каталог пока никем
     * не потребляется (обработчик синхронизации приезжает вместе
     * с парсером), и компилятор вырезает неиспользуемый private-сервис —
     * та же причина, что у DoctrineUserRepository в пакете 1.
     */
    private function listings(): MarketplaceListingRepository
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return new DoctrineMarketplaceListingWriter($connection);
    }

    private function salesFacts(): SalesFactRepository
    {
        /** @var SalesFactRepository $repository */
        $repository = static::getContainer()->get(SalesFactRepository::class);

        return $repository;
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

final readonly class ConnectedCompany
{
    public function __construct(
        public Company $company,
        public User $user,
        public ExtensionTokenSecret $secret,
    ) {
    }
}
