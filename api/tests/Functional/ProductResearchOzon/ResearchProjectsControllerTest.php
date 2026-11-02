<?php

declare(strict_types=1);

namespace App\Tests\Functional\ProductResearchOzon;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\ProductResearchOzon\Domain\ResearchProjectRepository;
use App\ProductResearchOzon\Infrastructure\UnavailableCategoryLookup;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\ResearchProjectBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\Fake\ResearchCategories;
use App\Tests\Support\Fake\ResearchClock;
use App\Tests\Support\ProductResearchOzon\ResearchServices;
use App\Tests\Support\SessionWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class ResearchProjectsControllerTest extends SessionWebTestCase
{
    use ResearchServices;
    private ?string $previousFlag = null;
    private string $actor;
    private \App\Identity\Domain\User $member;
    private \App\Identity\Domain\Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $old = $_ENV['PRODUCT_RESEARCH_OZON_ENABLED'] ?? null;
        self::assertTrue(null === $old || \is_string($old));
        $this->previousFlag = $old;
        $_ENV['PRODUCT_RESEARCH_OZON_ENABLED'] = $_SERVER['PRODUCT_RESEARCH_OZON_ENABLED'] = '1';
    }

    protected function tearDown(): void
    {
        if (null === $this->previousFlag) {
            unset($_ENV['PRODUCT_RESEARCH_OZON_ENABLED'], $_SERVER['PRODUCT_RESEARCH_OZON_ENABLED']);
        } else {
            $_ENV['PRODUCT_RESEARCH_OZON_ENABLED'] = $_SERVER['PRODUCT_RESEARCH_OZON_ENABLED'] = $this->previousFlag;
        }
        parent::tearDown();
    }

    public function testFullApiPathKeepsPeriodsAuthorAndNoopVersion(): void
    {
        [$client, $company] = $this->fixture();
        $base = $this->base($company);
        $body = $this->valid();
        $this->post($client, $base.'/preview', ['periods' => $body['periods'], 'categoryPath' => 'Одежда/Хиджабы']);
        self::assertSame(200, $client->getResponse()->getStatusCode(), substr((string) $client->getResponse()->getContent(), 0, 250));
        self::assertSame(6, $this->json($client)['monthCount']);
        $this->post($client, $base.'/projects', $body);
        self::assertSame(201, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $created = $this->json($client);
        self::assertSame($this->actor, $created['createdByUserId']);
        self::assertSame('draft', $created['status']);
        self::assertSame('Ручное имя', $created['name']);
        self::assertSame('Комментарий', $created['comment']);
        self::assertTrue($created['fbs']);
        self::assertSame('RUB', $created['currency']);
        $id = $created['id'];
        self::assertIsString($id);
        $client->request('GET', $base.'/projects/'.$id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($created, $this->json($client));
        $client->request('GET', $base.'/projects?limit=1');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $items = $this->json($client)['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        $periods = [['fromMonth' => '2026-03', 'toMonth' => '2026-04', 'name' => 'Продление']];
        $this->post($client, $base.'/projects/'.$id.'/periods', ['periods' => $periods, 'includeCurrentMonth' => true, 'expectedVersion' => 1, 'previewCalendarDate' => '2026-04-15']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(2, $this->json($client)['version']);
        $preview = $this->json($client)['preview'];
        self::assertIsArray($preview);
        self::assertSame(7, $preview['monthCount']);
        $this->post($client, $base.'/projects/'.$id.'/periods', ['periods' => $periods, 'expectedVersion' => 2, 'previewCalendarDate' => '2026-04-15']);
        self::assertSame(2, $this->json($client)['version']);
        $this->post($client, $base.'/projects/'.$id.'/copy', ['expectedVersion' => 2.0, 'previewCalendarDate' => '2026-04-15', 'overrides' => ['name' => 'Копия', 'categoryPath' => 'Другая категория']]);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        self::assertNotSame($id, $this->json($client)['id']);
        self::assertSame('Другая категория', $this->json($client)['categoryPath']);
        self::assertSame(1, $this->json($client)['version']);
    }

    public function testCompanyAndProjectIsolationCoversEveryOperation(): void
    {
        [$client, $company] = $this->fixture();
        $foreign = CompanyBuilder::aCompany()->persistWith(self::service(CompanyRepository::class));
        $project = ResearchProjectBuilder::aProject()->withCompanyId($foreign->id())->persistWith(self::service(ResearchProjectRepository::class));
        $foreignBase = $this->base($foreign->id()->toRfc4122());
        foreach (['/preview', '/projects', '/projects/'.$project->id()->toRfc4122().'/periods', '/projects/'.$project->id()->toRfc4122().'/copy', '/projects/'.$project->id()->toRfc4122().'/runs'] as $suffix) {
            $this->post($client, $foreignBase.$suffix, []);
            self::assertSame(403, $client->getResponse()->getStatusCode());
        }
        foreach (['/projects', '/projects/'.$project->id()->toRfc4122(), '/projects/'.$project->id()->toRfc4122().'/runs/'.Uuid::v7()->toRfc4122()] as $suffix) {
            $client->request('GET', $foreignBase.$suffix);
            self::assertSame(403, $client->getResponse()->getStatusCode());
        }
        $base = $this->base($company).'/projects/'.$project->id()->toRfc4122();
        $client->request('GET', $base);
        self::assertSame(404, $client->getResponse()->getStatusCode());
        foreach (['/periods' => ['periods' => [['fromMonth' => '2026-01', 'toMonth' => '2026-01']]], '/copy' => []] as $suffix => $extra) {
            $this->post($client, $base.$suffix, [...$extra, 'expectedVersion' => 1, 'previewCalendarDate' => '2026-04-15']);
            self::assertSame(404, $client->getResponse()->getStatusCode());
        }
        $client->request('GET', $this->base($company).'/projects?cursor='.$project->id()->toRfc4122());
        self::assertSame([], $this->json($client)['items']);
    }

    public function testCategoryAndCatalogReadinessAreDifferent(): void
    {
        [$client, $company] = $this->fixture();
        $this->post($client, $this->base($company).'/projects', [...$this->valid(), 'categoryPath' => 'Нет в справочнике']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('unknown_category', $this->json($client)['code']);
        self::service(ResearchCategories::class)->publish($company, []);
        $this->post($client, $this->base($company).'/projects', $this->valid());
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $client->request('GET', $this->base($company).'/projects');
        self::assertSame([], $this->json($client)['items']);
        $this->expectException(\App\ProductResearchOzon\Domain\ResearchProblem::class);
        (new UnavailableCategoryLookup())->resolve($company, 'Одежда/Хиджабы');
    }

    public function testNewDayAndOldVersionDoNotChangeProject(): void
    {
        [$client, $company] = $this->fixture();
        $base = $this->base($company).'/projects';
        $this->post($client, $base, $this->valid());
        $id = $this->json($client)['id'];
        self::assertIsString($id);
        self::service(ResearchClock::class)->freeze('2026-04-16T01:00:00Z');
        $this->post($client, $base.'/'.$id.'/periods', ['expectedVersion' => 1, 'previewCalendarDate' => '2026-04-15', 'periods' => [['fromMonth' => '2026-04', 'toMonth' => '2026-04']], 'includeCurrentMonth' => true]);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame('preview_outdated', $this->json($client)['code']);
        $this->post($client, $base.'/'.$id.'/copy', ['expectedVersion' => 99, 'previewCalendarDate' => '2026-04-16']);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame('version_conflict', $this->json($client)['code']);
        $client->request('GET', $base.'/'.$id);
        self::assertSame(1, $this->json($client)['version']);
    }

    public function testDisabledFlagProtectsDirectCalls(): void
    {
        $_ENV['PRODUCT_RESEARCH_OZON_ENABLED'] = $_SERVER['PRODUCT_RESEARCH_OZON_ENABLED'] = '0';
        [$client, $company] = $this->fixture();
        $base = $this->base($company);
        $id = Uuid::v7()->toRfc4122();
        foreach (['/preview', '/projects', '/projects/'.$id.'/periods', '/projects/'.$id.'/copy', '/projects/'.$id.'/runs'] as $suffix) {
            $this->post($client, $base.$suffix, []);
            self::assertSame(404, $client->getResponse()->getStatusCode());
            self::assertSame('feature_disabled', $this->json($client)['code']);
        }
        foreach (['/projects', '/projects/'.$id, '/projects/'.$id.'/runs/'.Uuid::v7()->toRfc4122()] as $suffix) {
            $client->request('GET', $base.$suffix);
            self::assertSame(404, $client->getResponse()->getStatusCode());
        }
    }

    public function testAnonymousAndBearerCannotUseSellerEndpoints(): void
    {
        $client = self::createClient();
        $path = $this->base(Uuid::v7()->toRfc4122()).'/projects';
        foreach ([[], ['HTTP_AUTHORIZATION' => 'Bearer test-extension-token']] as $headers) {
            $client->request('GET', $path, server: $headers);
            self::assertSame(401, $client->getResponse()->getStatusCode());
            $client->request('POST', $path.'/'.Uuid::v7()->toRfc4122().'/runs', server: $headers);
            self::assertSame(401, $client->getResponse()->getStatusCode());
            $client->request('GET', $path.'/'.Uuid::v7()->toRfc4122().'/runs/'.Uuid::v7()->toRfc4122(), server: $headers);
            self::assertSame(401, $client->getResponse()->getStatusCode());
        }
    }

    public function testCatalogUnavailableIs503AndNoWrite(): void
    {
        [$client, $company] = $this->fixture();
        self::service(ResearchCategories::class)->forget($company);
        $this->post($client, $this->base($company).'/projects', $this->valid());
        self::assertSame(503, $client->getResponse()->getStatusCode());
        self::assertSame('catalog_not_ready', $this->json($client)['code']);
        $client->request('GET', $this->base($company).'/projects');
        self::assertSame([], $this->json($client)['items']);
    }

    public function testBlockedCompanyAndCsrfAreDeniedBeforeWrite(): void
    {
        [$client, $company] = $this->fixture();
        $client->request('POST', $this->base($company).'/projects', server: ['HTTP_X_CONWIX_REQUEST' => '0', 'CONTENT_TYPE' => 'application/json'], content: json_encode($this->valid(), \JSON_THROW_ON_ERROR));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('POST', $this->base($company).'/projects', server: ['HTTP_ORIGIN' => 'https://attacker.example', 'CONTENT_TYPE' => 'application/json'], content: json_encode($this->valid(), \JSON_THROW_ON_ERROR));
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::service(\Doctrine\DBAL\Connection::class)->executeStatement("UPDATE company SET status = 'blocked' WHERE id = :id", ['id' => $company]);
        $client->request('GET', $this->base($company).'/projects');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertSame('company_blocked', $this->json($client)['code']);
    }

    public function testAdminSessionDoesNotActAsSeller(): void
    {
        $client = self::createClient();
        $admin = \App\Tests\Support\Builder\AdministratorBuilder::anAdministrator()->persistWith(self::service(\App\Identity\Domain\AdministratorRepository::class));
        $client->loginUser($admin, 'admin');
        $client->request('GET', $this->base(Uuid::v7()->toRfc4122()).'/projects');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testRawJsonLimitsShortcutsAndCursorBoundary(): void
    {
        [$client, $company] = $this->fixture();
        $base = $this->base($company);
        foreach (['[]', 'null', '{broken', str_repeat(' ', 32769)] as $body) {
            $client->request('POST', $base.'/projects', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
            self::assertSame(422, $client->getResponse()->getStatusCode());
        }
        foreach (['limit=0', 'limit=201', 'cursor[]=bad', 'cursor=00000000000000000000000000', 'cursor=1111111111111111111111', 'token=not-a-filter'] as $query) {
            $client->request('GET', $base.'/projects?'.$query);
            self::assertSame(422, $client->getResponse()->getStatusCode());
        }
        $this->post($client, $base.'/preview', ['shortcut' => ['kind' => 'last_closed_months', 'months' => 3.0]]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(3, $this->json($client)['monthCount']);
        $body = $this->valid();
        $body['periods'] = array_fill(0, 25, ['fromMonth' => '2026-01', 'toMonth' => '2026-01']);
        $this->post($client, $base.'/projects', $body);
        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testValidExtensionTokenCannotReplaceSellerSession(): void
    {
        [$client, $company] = $this->fixture();
        $secret = \App\Identity\Domain\ValueObject\ExtensionTokenSecret::generate();
        \App\Tests\Support\Builder\ExtensionTokenBuilder::anExtensionToken()->withCompany($this->company)->withUser($this->member)->withSecret($secret)
            ->persistWith(self::service(CompanyRepository::class), self::service(DoctrineUserRepository::class), self::service(\App\Identity\Domain\ExtensionTokenRepository::class));
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer '.$secret->plaintext()];
        $client->request('GET', '/api/extension/me', server: $headers);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->getCookieJar()->clear();
        $client->request('GET', $this->base($company).'/projects', server: $headers);
        self::assertSame(401, $client->getResponse()->getStatusCode());
        $client->request('POST', $this->base($company).'/projects', server: [...$headers, 'CONTENT_TYPE' => 'application/json'], content: json_encode($this->valid(), \JSON_THROW_ON_ERROR));
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testWhitespaceNameUsesSuggestedName(): void
    {
        [$client, $company] = $this->fixture();
        $this->post($client, $this->base($company).'/projects', [...$this->valid(), 'name' => '  ']);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        self::assertSame('Хиджабы · янв–мар 2025, янв–мар 2026', $this->json($client)['name']);
    }

    #[DataProvider('badBodies')]
    public function testBoundaryRejectsMalformedAndPrivilegedFields(string $extra, string $rawValue): void
    {
        [$client, $company] = $this->fixture();
        $body = $this->valid();
        $body[$extra] = json_decode($rawValue, flags: \JSON_THROW_ON_ERROR);
        $this->post($client, $this->base($company).'/projects', $body);
        self::assertSame(422, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
    }

    /** @return iterable<array{string,string}> */
    public static function badBodies(): iterable
    {
        yield ['author', '"forged"'];
        yield ['companyId', '"forged"'];
        yield ['id', '"forged"'];
        yield ['status', '"ready"'];
        yield ['fbs', 'false'];
        yield ['currency', '"USD"'];
        yield ['includeCurrentMonth', '"true"'];
        yield ['name', 'null'];
        yield ['name', '"'.str_repeat('x', 201).'"'];
        yield ['comment', '"'.str_repeat('x', 2001).'"'];
        yield ['periods', '{}'];
        yield ['periods', '[{"fromMonth":"2026-01-01","toMonth":"2026-02"}]'];
        yield ['shortcut', '{"kind":"last_closed_months","months":3}'];
        yield ['previewCalendarDate', '"2026-02-30"'];
    }

    #[DataProvider('nullByteOperations')]
    public function testNullBytesReturn422WithoutWrites(string $operation): void
    {
        [$client, $company] = $this->fixture();
        $base = $this->base($company).'/projects';
        $this->post($client, $base, $this->valid());
        self::assertSame(201, $client->getResponse()->getStatusCode());
        $id = $this->json($client)['id'];
        self::assertIsString($id);
        $db = self::service(\Doctrine\DBAL\Connection::class);
        $before = $db->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = :company', ['company' => $company]);
        $common = ['expectedVersion' => 1, 'previewCalendarDate' => '2026-04-15'];
        [$suffix, $body] = match ($operation) {
            'create' => ['', [...$this->valid(), 'comment' => "\0"]],
            'copy' => ['/'.$id.'/copy', [...$common, 'overrides' => ['name' => "bad\0name"]]],
            'add' => ['/'.$id.'/periods', [...$common, 'periods' => [['fromMonth' => '2026-01', 'toMonth' => '2026-02', 'name' => "bad\0period"]]]],
            default => throw new \LogicException('invalid_test_case'),
        };
        $this->post($client, $base.$suffix, $body);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame($before, $db->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = :company', ['company' => $company]));
        self::assertSame(1, $db->fetchOne('SELECT COUNT(*) FROM product_research_ozon_project WHERE company_id = :company', ['company' => $company]));
        $client->request('GET', $base.'/'.$id);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(1, $this->json($client)['version']);
    }

    /** @return iterable<array{string}> */
    public static function nullByteOperations(): iterable
    {
        yield ['create'];
        yield ['copy'];
        yield ['add'];
    }

    public function testRunEndpointsPersistWithoutSynchronousServiceCallAndKeepFrozenMonths(): void
    {
        [$client, $company] = $this->fixture();
        self::getContainer()->set(\App\ProductResearchOzon\Infrastructure\Config\CatalogContext::class, new \App\ProductResearchOzon\Infrastructure\Config\CatalogContext(Uuid::v7()->toRfc4122()));
        $project = ResearchProjectBuilder::aProject()->withCompanyId(Uuid::fromString($company))->persistWith(self::service(ResearchProjectRepository::class));
        $base = $this->base($company).'/projects/'.$project->id()->toRfc4122();
        $input = ['requestId' => Uuid::v7()->toRfc4122(), 'expectedVersion' => 1, 'previewCalendarDate' => '2026-04-15'];
        $this->post($client, $base.'/runs', $input);
        self::assertSame(202, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $first = $this->json($client);
        self::assertSame('queued', $first['status']);
        self::assertFalse($first['delivered']);
        $run = $first['id'];
        self::assertIsString($run);
        $transport = self::getContainer()->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
        $client->request('GET', $base.'/runs/'.$run);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame($first, $this->json($client));
        $this->post($client, $base.'/periods', ['periods' => [['fromMonth' => '2025-01', 'toMonth' => '2025-01']], 'expectedVersion' => 2, 'previewCalendarDate' => '2026-04-15']);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('loading', $this->json($client)['status']);
        $client->request('GET', $base.'/runs/'.$run);
        self::assertSame($first, $this->json($client));
        // The old run contains only January–March, while the project now also needs 2025-01.
        $repo = self::service(\App\ProductResearchOzon\Domain\RunDeliveryRepository::class);
        $view = (self::service(\App\ProductResearchOzon\Application\GetResearchRunAction::class))($company, $project->id()->toRfc4122(), $run);
        $owner = Uuid::v7()->toRfc4122();
        $at = new \DateTimeImmutable('2026-04-15T10:00:00Z');
        self::assertNotNull($repo->claimDelivery($company, $run, $owner, $at));
        self::assertTrue($repo->delivered($company, $run, $owner, $at));
        self::assertNotNull($repo->claimPoll($company, $run, $owner, $at));
        $ready = array_map(static fn (\App\ProductResearchOzon\Domain\ValueObject\RunMonthState $month): \App\ProductResearchOzon\Domain\ValueObject\RunMonthState => new \App\ProductResearchOzon\Domain\ValueObject\RunMonthState($month->period, 'ready', Uuid::v7()->toRfc4122(), 1, null, new \App\ProductResearchOzon\Domain\ValueObject\RunProvenance('api', $at, $at)), $view->progress->months);
        self::assertTrue($repo->apply($company, $run, $owner, new \App\ProductResearchOzon\Domain\ValueObject\RunProgress($company, $run, $view->progress->sourceId, 'ready', 9, $ready), $at));
        $client->request('GET', $base);
        self::assertSame('partially_loaded', $this->json($client)['status']);
        $client->request('GET', $this->base($company).'/projects');
        $items = $this->json($client)['items'];
        self::assertIsArray($items);
        self::assertIsArray($items[0]);
        self::assertSame('partially_loaded', $items[0]['status']);
        $this->post($client, $base.'/periods', ['periods' => [['fromMonth' => '2025-01', 'toMonth' => '2025-01']], 'expectedVersion' => 3, 'previewCalendarDate' => '2026-04-15']);
        self::assertSame('partially_loaded', $this->json($client)['status']);
        $this->post($client, $base.'/copy', ['expectedVersion' => 3, 'previewCalendarDate' => '2026-04-15']);
        self::assertSame(201, $client->getResponse()->getStatusCode());
        self::assertSame('draft', $this->json($client)['status']);
        $client->request('GET', $base.'/runs/'.$run);
        $first = $this->json($client);
        $months = $first['months'];
        self::assertIsArray($months);
        self::assertIsArray($months[0]);
        self::assertSame('api', $months[0]['origin']);
        self::assertSame('2026-04-15T10:00:00.000000+00:00', $months[0]['collectedAt']);
        self::service(ResearchClock::class)->freeze('2026-04-16T09:00:00Z');
        $this->post($client, $base.'/runs', $input);
        self::assertSame(202, $client->getResponse()->getStatusCode());
        self::assertSame($first, $this->json($client));
        $this->post($client, $base.'/runs', [...$input, 'unknown' => true]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $client->request('GET', $this->base($company).'/projects/'.Uuid::v7()->toRfc4122().'/runs/'.$run);
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    /** @return array{KernelBrowser, string} */
    private function fixture(): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $company = CompanyBuilder::aCompany()->persistWith(self::service(CompanyRepository::class));
        $user = UserBuilder::aUser()->persistWith(self::service(DoctrineUserRepository::class));
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith(self::service(CompanyRepository::class), self::service(DoctrineUserRepository::class), self::service(DoctrineCompanyMemberRepository::class));
        $this->member = $user;
        $this->company = $company;
        $this->actor = $user->id()->toRfc4122();
        $client->loginUser($user, 'api');
        self::service(ResearchCategories::class)->publish($company->id()->toRfc4122(), ['Одежда/Хиджабы', 'Другая категория']);

        return [$client, $company->id()->toRfc4122()];
    }

    /** @return array<string,mixed> */
    private function valid(): array
    {
        return ['categoryPath' => 'Одежда/Хиджабы', 'periods' => [['fromMonth' => '2025-01', 'toMonth' => '2025-03', 'name' => 'Прошлый сезон'], ['fromMonth' => '2026-01', 'toMonth' => '2026-03', 'name' => 'Этот сезон']], 'previewCalendarDate' => '2026-04-15', 'name' => 'Ручное имя', 'comment' => 'Комментарий'];
    }

    private function base(string $company): string
    {
        return '/api/companies/'.$company.'/product-research-ozon';
    }

    /** @param array<string,mixed> $body */
    private function post(KernelBrowser $client, string $path, array $body): void
    {
        $client->request('POST', $path, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode((object) $body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client): array
    {
        $body = $client->getResponse()->getContent();
        self::assertIsString($body);
        /** @var array<string,mixed> $data */
        $data = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);

        return $data;
    }
}
