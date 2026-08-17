<?php

declare(strict_types=1);

namespace App\Tests\Functional\PriceMonitoring;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineExtensionTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\PriceMonitoring\Application\StartTrackingAction;
use App\PriceMonitoring\Domain\TrackedSkuRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\ExtensionTokenBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\TrackedSkuBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Список отслеживания расширением (ADR-014). Обязательное покрытие
 * ADR-005: идемпотентность включения и изоляция данных между компаниями —
 * последняя дважды, по членству и по области действия токена.
 */
final class TrackedSkuControllerTest extends WebTestCase
{
    public function testTrackingTheSameSkuTwiceDoesNotCreateASecondRecord(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        $this->start($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(200);
        // Повтор — не ошибка и не вторая строка: расширение переотправляет
        // запрос при сетевом сбое, а человек кликает дважды.
        $this->start($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['100000001'], $this->list($client, $fixture)['items']);
    }

    public function testTrackingAgainResumesAStoppedSku(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        $this->start($client, $fixture, '100000001');
        $this->stop($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->list($client, $fixture)['items']);

        // Возвращает прежнюю строку в active, а не заводит вторую:
        // уникальный индекс второй бы и не позволил, и это ровно
        // то поведение, которое нужно.
        $this->start($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['100000001'], $this->list($client, $fixture)['items']);
    }

    public function testStoppingWhatIsNotTrackedIsNotFound(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        $this->stop($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(404);

        $this->start($client, $fixture, '100000001');
        $this->stop($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(200);
        // Повторная остановка — тоже 404: успех означал бы, что мы
        // остановили что-то, чего не отслеживали.
        $this->stop($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(404);
    }

    public function testListShowsOnlyOwnCompanyActiveSkus(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        $this->start($client, $fixture, '100000001');
        $this->start($client, $fixture, '100000002');
        $this->stop($client, $fixture, '100000002');

        // Чужая компания с тем же артикулом: артикулы площадки общие
        // для всех продавцов, и изоляцию держит company_id в самом SQL,
        // а не то, что до контроллера дошёл верный токен.
        TrackedSkuBuilder::aTrackedSku()
            ->withCompanyId(Uuid::v7())
            ->withMarketplaceSku('100000001')
            ->persistWith($this->trackedSkus());
        TrackedSkuBuilder::aTrackedSku()
            ->withCompanyId(Uuid::v7())
            ->withMarketplaceSku('999999999')
            ->persistWith($this->trackedSkus());

        $payload = $this->list($client, $fixture);

        self::assertSame(['100000001'], $payload['items']);
        self::assertNull($payload['nextCursor']);
    }

    public function testListPagesByCursor(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        foreach (['100000001', '100000002', '100000003'] as $sku) {
            $this->start($client, $fixture, $sku);
        }

        $first = $this->list($client, $fixture, '?limit=2');
        self::assertSame(['100000001', '100000002'], $first['items']);
        self::assertSame('100000002', $first['nextCursor']);

        $second = $this->list($client, $fixture, '?limit=2&cursor=100000002');
        self::assertSame(['100000003'], $second['items']);
        self::assertNull($second['nextCursor'], 'последняя страница не предлагает следующую');
    }

    public function testTokenOfAnotherCompanyIsRejectedEvenForItsOwnMember(): void
    {
        $client = static::createClient();
        [$companies, $users, $members, $tokens] = $this->repositories();

        // Один человек в двух компаниях: проверки членства мало — она
        // ответит «да» для обеих. Область действия токена проверяет
        // ExtensionTokenScopeSubscriber, и без companyId в пути этой
        // проверки не было бы вовсе.
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
            '/api/extension/companies/'.$second->id()->toRfc4122().'/tracked-skus',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$secret->plaintext()],
            content: json_encode(['marketplaceSku' => '100000001'], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testWithoutTokenTheEndpointsAreUnauthorized(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();
        $base = '/api/extension/companies/'.$fixture->company->id()->toRfc4122().'/tracked-skus';

        $client->request('GET', $base);
        self::assertResponseStatusCodeSame(401);

        $client->request('POST', $base, content: json_encode(['marketplaceSku' => '100000001'], \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(401);
    }

    public function testTrackingRequiresAnActiveOzonConnection(): void
    {
        $client = static::createClient();
        // Подключения нет вовсе.
        $fixture = $this->connectedCompany(withAccount: false);

        $this->start($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('no_active_ozon_connection', $this->payload($client)['code']);
    }

    public function testBrokenConnectionIsNotAnActiveOne(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany(withAccount: false);

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($fixture->company)
            ->withState(MarketplaceAccountState::Broken)
            ->persistWith($this->companies(), $this->marketplaceAccounts());

        // ADR-007: broken не синхронизируется. Привязывать к нему новое
        // отслеживание означало бы обещать данные, которых не будет.
        $this->start($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('no_active_ozon_connection', $this->payload($client)['code']);
    }

    public function testTwoActiveOzonConnectionsAreRejected(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($fixture->company)
            ->withExternalShopId('second-shop')
            ->persistWith($this->companies(), $this->marketplaceAccounts());

        // Выбирать кабинет за продавца нельзя, а строить выбор
        // в интерфейсе заранее — тем более (ADR-014, вопрос №3).
        $this->start($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('multiple_ozon_connections', $this->payload($client)['code']);
    }

    public function testTrackingStopsAtTheLimit(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        for ($i = 0; $i < StartTrackingAction::MAX_TRACKED; ++$i) {
            TrackedSkuBuilder::aTrackedSku()
                ->withCompany($fixture->company)
                ->withMarketplaceAccount($fixture->account())
                ->withMarketplaceSku(\sprintf('2000000%02d', $i))
                ->persistWith($this->trackedSkus());
        }

        $this->start($client, $fixture, '100000001');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracked_sku_limit_reached', $this->payload($client)['code']);
    }

    public function testMalformedInputIsRejected(): void
    {
        $client = static::createClient();
        $fixture = $this->connectedCompany();

        $this->start($client, $fixture, '');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('marketplace_sku_required', $this->payload($client)['code']);

        $client->request(
            'POST',
            '/api/extension/companies/'.$fixture->company->id()->toRfc4122().'/tracked-skus',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture->secret->plaintext()],
            content: 'not json',
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('malformed_json', $this->payload($client)['code']);

        $this->list($client, $fixture, '?limit=201');
        self::assertResponseStatusCodeSame(422);
    }

    private function start(KernelBrowser $client, ConnectedCompany $fixture, string $marketplaceSku): void
    {
        $client->request(
            'POST',
            '/api/extension/companies/'.$fixture->company->id()->toRfc4122().'/tracked-skus',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture->secret->plaintext()],
            content: json_encode(['marketplaceSku' => $marketplaceSku], \JSON_THROW_ON_ERROR),
        );
    }

    private function stop(KernelBrowser $client, ConnectedCompany $fixture, string $marketplaceSku): void
    {
        $client->request(
            'POST',
            '/api/extension/companies/'.$fixture->company->id()->toRfc4122().'/tracked-skus/'.$marketplaceSku.'/stop',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture->secret->plaintext()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function list(KernelBrowser $client, ConnectedCompany $fixture, string $query = ''): array
    {
        $client->request(
            'GET',
            '/api/extension/companies/'.$fixture->company->id()->toRfc4122().'/tracked-skus'.$query,
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$fixture->secret->plaintext()],
        );

        return $this->payload($client);
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

    private function connectedCompany(bool $withAccount = true): ConnectedCompany
    {
        [$companies, $users, $members, $tokens] = $this->repositories();

        $company = CompanyBuilder::aCompany()->withName('Acme LLC')->persistWith($companies);
        $user = UserBuilder::aUser()->withEmail('owner@example.com')->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $members);

        $account = null;
        if ($withAccount) {
            $account = MarketplaceAccountBuilder::aMarketplaceAccount()
                ->withCompany($company)
                ->persistWith($companies, $this->marketplaceAccounts());
        }

        $secret = ExtensionTokenSecret::generate();
        ExtensionTokenBuilder::anExtensionToken()
            ->withCompany($company)
            ->withUser($user)
            ->withSecret($secret)
            ->persistWith($companies, $users, $tokens);

        return new ConnectedCompany($company, $user, $secret, $account);
    }

    private function trackedSkus(): TrackedSkuRepository
    {
        /** @var TrackedSkuRepository $repository */
        $repository = static::getContainer()->get(TrackedSkuRepository::class);

        return $repository;
    }

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $repository */
        $repository = static::getContainer()->get(MarketplaceAccountRepository::class);

        return $repository;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $repository */
        $repository = static::getContainer()->get(CompanyRepository::class);

        return $repository;
    }

    /**
     * @return array{0: CompanyRepository, 1: DoctrineUserRepository, 2: DoctrineCompanyMemberRepository, 3: DoctrineExtensionTokenRepository}
     */
    private function repositories(): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return [
            $this->companies(),
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
        private ?MarketplaceAccount $account,
    ) {
    }

    public function account(): MarketplaceAccount
    {
        return $this->account ?? throw new \LogicException('Фикстура собрана без подключения — этому тесту оно нужно.');
    }
}
