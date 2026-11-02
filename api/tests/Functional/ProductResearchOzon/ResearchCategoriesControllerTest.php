<?php

declare(strict_types=1);

namespace App\Tests\Functional\ProductResearchOzon;

use App\Identity\Application\ChangeCompanyStatusAction;
use App\Identity\Domain\AdministratorRepository;
use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\ExtensionTokenRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\CompanyStatus;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\ProductResearchOzon\Application\ResearchPreparation;
use App\ProductResearchOzon\Domain\CatalogProjectionRepository;
use App\ProductResearchOzon\Domain\ResearchCalendar;
use App\ProductResearchOzon\Domain\ValueObject\CatalogMetadata;
use App\ProductResearchOzon\Infrastructure\DatabaseCategoryLookup;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\CatalogCategoryEntryBuilder;
use App\Tests\Support\Builder\CatalogProjectionBuilder;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\ExtensionTokenBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\Fake\ResearchClock;
use App\Tests\Support\ProductResearchOzon\ResearchServices;
use App\Tests\Support\SessionWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class ResearchCategoriesControllerTest extends SessionWebTestCase
{
    use ResearchServices;
    private const string SOURCE = '019a3c00-0000-7000-8000-000000000011';
    private const string PATH = 'Аксессуары/Хиджабы и тюрбаны женские';
    /** @var array<string,?string> */
    private array $previous = [];
    private Company $company;
    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['PRODUCT_RESEARCH_OZON_ENABLED' => '1', 'RESEARCH_CATALOG_SOURCE_ID' => self::SOURCE, 'RESEARCH_SERVICE_TOKEN' => str_repeat('s', 40)] as $name => $value) {
            $old = $_ENV[$name] ?? null;
            self::assertTrue(null === $old || \is_string($old));
            $this->previous[$name] = $old;
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            if (null === $value) {
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
        parent::tearDown();
    }

    public function testSearchIsLocalLiteralBoundedAndDoesNotSlideTtl(): void
    {
        [$client, $company] = $this->fixture();
        $this->seed($company, [self::PATH, 'Дом/Хиджаб с узором', 'Текстиль/Хидж_Особый']);
        $http = new MockHttpClient(new MockResponse('{}'));
        self::getContainer()->set('http_client', $http);
        $client->request('GET', $this->url($company).'&limit=1');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $items = $this->json($client)['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        self::assertSame([['path' => self::PATH]], $this->json($client)['items']);
        $first = $this->json($client);
        self::service(ResearchClock::class)->freeze('2026-04-15T20:00:00Z');
        $client->request('GET', $this->url($company));
        self::assertSame($first['generatedAt'], $this->json($client)['generatedAt']);
        self::assertSame($first['expiresAt'], $this->json($client)['expiresAt']);
        $client->request('GET', $this->url($company, 'Хидж%'));
        self::assertSame([], $this->json($client)['items']);
        $client->request('GET', $this->url($company, 'Хидж_'));
        self::assertSame([['path' => 'Текстиль/Хидж_Особый']], $this->json($client)['items']);
        self::assertSame(0, $http->getRequestsCount(), 'Seller search must not call Python or MPStats');
    }

    public function testMissingExpiredAndActualEmptyAreDifferent(): void
    {
        [$client, $company] = $this->fixture();
        $client->request('GET', $this->url($company));
        self::assertSame(503, $client->getResponse()->getStatusCode());
        $this->seed($company, []);
        $client->request('GET', $this->url($company));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame([], $this->json($client)['items']);
        self::service(ResearchClock::class)->freeze('2026-04-16T09:00:00Z');
        $client->request('GET', $this->url($company));
        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertSame('catalog_not_ready', $this->json($client)['code']);
    }

    public function testCompanyAndSourceIsolationAndBlockedCompany(): void
    {
        [$client, $company] = $this->fixture();
        $this->seed($company, [self::PATH]);
        $foreign = CompanyBuilder::aCompany()->persistWith(self::service(CompanyRepository::class))->id()->toRfc4122();
        $this->seed($foreign, ['Секретная категория']);
        $this->seed($company, ['Другое подключение'], Uuid::v7()->toRfc4122());
        foreach (['Секретная', 'Другое'] as $query) {
            $client->request('GET', $this->url($company, $query));
            self::assertSame([], $this->json($client)['items']);
        }
        $client->request('GET', $this->url($foreign));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $admin = AdministratorBuilder::anAdministrator()->persistWith(self::service(AdministratorRepository::class));
        (self::service(ChangeCompanyStatusAction::class))($company, CompanyStatus::Blocked, $admin);
        $client->request('GET', $this->url($company));
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testRenameKeepsExistingProjectAndCopyRevalidatesChoice(): void
    {
        [$client, $company] = $this->fixture();
        $this->seed($company, [self::PATH]);
        self::getContainer()->set(ResearchPreparation::class, new ResearchPreparation(new ResearchCalendar(), self::service(DatabaseCategoryLookup::class), self::service(ResearchClock::class)));
        $base = '/api/companies/'.$company.'/product-research-ozon/projects';
        $body = ['categoryPath' => self::PATH, 'periods' => [['fromMonth' => '2026-01', 'toMonth' => '2026-03']], 'previewCalendarDate' => '2026-04-15'];
        $this->post($client, $base, [...$body, 'categoryPath' => 'Произвольный текст']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->post($client, $base, $body);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $id = $this->json($client)['id'];
        self::assertIsString($id);
        $clock = self::service(ResearchClock::class);
        $clock->freeze('2026-04-16T10:00:00Z');
        $repo = self::service(CatalogProjectionRepository::class);
        $owner = Uuid::v7()->toRfc4122();
        self::assertTrue($repo->claim($company, self::SOURCE, $owner, $clock->now()));
        $repo->publish($company, self::SOURCE, $owner, new CatalogMetadata(2, $clock->now()), ['Аксессуары/Переименованная категория'], $clock->now());
        $client->request('GET', $base.'/'.$id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(self::PATH, $this->json($client)['categoryPath']);
        $copy = ['expectedVersion' => 1, 'previewCalendarDate' => '2026-04-16'];
        $this->post($client, $base.'/'.$id.'/copy', $copy);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->post($client, $base.'/'.$id.'/copy', [...$copy, 'overrides' => ['categoryPath' => 'Аксессуары/Переименованная категория']]);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        self::assertSame('Аксессуары/Переименованная категория', $this->json($client)['categoryPath']);
    }

    public function testStrictParametersAndDisabledFlag(): void
    {
        [$client, $company] = $this->fixture();
        foreach (['q=хи', 'q[]=хидж', 'q=%00хидж', 'q=%%%25', 'q=хидж&limit=0', 'q=хидж&limit=201', 'q=хидж&extra=1', 'q='.str_repeat('a', 201)] as $query) {
            $client->request('GET', '/api/companies/'.$company.'/product-research-ozon/categories?'.$query);
            self::assertSame(422, $client->getResponse()->getStatusCode());
        }
        $_ENV['PRODUCT_RESEARCH_OZON_ENABLED'] = $_SERVER['PRODUCT_RESEARCH_OZON_ENABLED'] = '0';
        self::ensureKernelShutdown();
        $client = self::createClient();
        self::getContainer()->set(\App\ProductResearchOzon\Infrastructure\Config\ResearchRollout::class, new \App\ProductResearchOzon\Infrastructure\Config\ResearchRollout($this->company->id()->toRfc4122()));
        $client->loginUser($this->member, 'api');
        $client->request('GET', $this->url($company));
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testValidExtensionAndAnonymousCannotUseSearch(): void
    {
        [$client, $company] = $this->fixture();
        $secret = ExtensionTokenSecret::generate();
        ExtensionTokenBuilder::anExtensionToken()->withCompany($this->company)->withUser($this->member)->withSecret($secret)->persistWith(self::service(CompanyRepository::class), self::service(DoctrineUserRepository::class), self::service(ExtensionTokenRepository::class));
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$secret->plaintext()];
        $client->request('GET', '/api/extension/me', server: $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->getCookieJar()->clear();
        $client->request('GET', $this->url($company), server: $headers);
        self::assertSame(401, $client->getResponse()->getStatusCode());
        $client->request('GET', $this->url($company));
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testAdminSessionCannotUseSellerSearch(): void
    {
        $client = self::createClient();
        $admin = AdministratorBuilder::anAdministrator()->persistWith(self::service(AdministratorRepository::class));
        $client->loginUser($admin, 'admin');
        $client->request('GET', $this->url(Uuid::v7()->toRfc4122()));
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    /** @return array{KernelBrowser,string} */
    private function fixture(): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->company = CompanyBuilder::aCompany()->persistWith(self::service(CompanyRepository::class));
        $this->member = UserBuilder::aUser()->persistWith(self::service(DoctrineUserRepository::class));
        CompanyMemberBuilder::aCompanyMember()->withCompany($this->company)->withUser($this->member)->persistWith(self::service(CompanyRepository::class), self::service(DoctrineUserRepository::class), self::service(DoctrineCompanyMemberRepository::class));
        self::getContainer()->set(\App\ProductResearchOzon\Infrastructure\Config\ResearchRollout::class, new \App\ProductResearchOzon\Infrastructure\Config\ResearchRollout($this->company->id()->toRfc4122()));
        $client->loginUser($this->member, 'api');

        return [$client, $this->company->id()->toRfc4122()];
    }

    /** @param list<string> $paths */
    private function seed(string $company, array $paths, string $source = self::SOURCE): void
    {
        $em = self::service(EntityManagerInterface::class);
        $catalog = CatalogProjectionBuilder::aCatalog()->withCompanyId(Uuid::fromString($company))->withSourceId(Uuid::fromString($source))->persistWith($em);
        foreach ($paths as $path) {
            CatalogCategoryEntryBuilder::aCategory()->inCatalog($catalog)->withPath($path)->persistWith($em);
        }
    }

    private function url(string $company, string $query = 'хидж'): string
    {
        return '/api/companies/'.$company.'/product-research-ozon/categories?q='.rawurlencode($query);
    }

    /** @param array<string,mixed> $body */
    private function post(KernelBrowser $client, string $url, array $body): void
    {
        $client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client): array
    {
        $raw = $client->getResponse()->getContent();
        self::assertIsString($raw);
        /** @var array<string,mixed> $result */
        $result = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);

        return $result;
    }
}
