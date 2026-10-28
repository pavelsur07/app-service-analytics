<?php

declare(strict_types=1);

namespace App\Tests\Functional\SalesPlanning;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanMetric;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\SalesPlanBuilder;
use App\Tests\Support\Builder\SalesPlanLineBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\SessionWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class SalesPlanCalendarControllerTest extends SessionWebTestCase
{
    public function testFullSaveDerivedBoundsPagingAndVersionBoundCursor(): void
    {
        [$client, $plan, $actor] = $this->fixture();
        $url = self::url($plan);
        $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $empty = self::body($client);
        self::assertSame([], self::path($empty, 'entries'));
        self::assertSame(['kind' => 'season_start', 'date' => '2026-07-01'], self::path($empty, 'seasonStart'));
        self::assertSame(['kind' => 'season_end', 'date' => '2026-07-31'], self::path($empty, 'seasonEnd'));
        $events = [
            ['kind' => 'sales', 'marketplaceSku' => null, 'dateFrom' => '2026-07-05', 'dateTo' => '2026-07-20'],
            ['kind' => 'replenishment', 'marketplaceSku' => 'KNOWN', 'dateFrom' => '2026-07-05', 'dateTo' => '2026-07-05'],
            ['kind' => 'replenishment_stop', 'marketplaceSku' => 'KNOWN', 'dateFrom' => '2026-07-21', 'dateTo' => '2026-07-21'],
        ];
        self::put($client, $url, ['version' => $plan->version(), 'entries' => $events]);
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $version = self::path(self::body($client), 'version');
        self::assertSame(2, $version);
        $client->request('GET', $url, ['limit' => '1']);
        $first = self::body($client);
        self::assertSame(2, self::path($first, 'version'));
        $cursor = self::path($first, 'nextCursor');
        self::assertIsString($cursor);
        $rawCursor = base64_decode($cursor, true);
        self::assertIsString($rawCursor);
        $cursorData = json_decode($rawCursor, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($cursorData);
        $cursorData['id'] = Uuid::v7()->toBase58();
        $client->request('GET', $url, ['limit' => '1', 'cursor' => base64_encode(json_encode($cursorData, \JSON_THROW_ON_ERROR))]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'Base58 UUID must be rejected before PostgreSQL CAST.');
        $outsideCursor = json_decode($rawCursor, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($outsideCursor);
        $outsideCursor['date'] = '9999-12-31';
        $client->request('GET', $url, ['limit' => '1', 'cursor' => base64_encode(json_encode($outsideCursor, \JSON_THROW_ON_ERROR))]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'A cursor beyond the last event is outside the page range.');
        $ids = [self::path($first, 'entries', 0, 'id')];
        $client->request('GET', $url, ['limit' => '1', 'cursor' => $cursor]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $second = self::body($client);
        $ids[] = self::path($second, 'entries', 0, 'id');
        $client->request('GET', $url, ['limit' => '1', 'cursor' => self::path($second, 'nextCursor')]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $third = self::body($client);
        $ids[] = self::path($third, 'entries', 0, 'id');
        foreach ($ids as $id) {
            self::assertIsString($id);
        }
        self::assertNotSame($ids[0], $ids[1]);
        self::assertNotSame($ids[1], $ids[2]);
        self::assertNotSame($ids[0], $ids[2]);
        self::assertNull(self::path($third, 'nextCursor'));
        self::assertSame('replenishment_stop', self::path($third, 'entries', 0, 'kind'));
        $client->request('GET', $url, ['limit' => '2', 'cursor' => $cursor]);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::put($client, $url, ['version' => $version, 'entries' => []]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('GET', $url, ['limit' => '1', 'cursor' => $cursor]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'A previous header version cannot page a changed calendar.');
        $client->request('GET', $url);
        self::assertSame([], self::path(self::body($client), 'entries'));
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame(6, $connection->fetchOne('SELECT COUNT(*) FROM audit_record WHERE company_id = ? AND actor_user_id = ? AND action IN (?, ?)', [$plan->companyId()->toRfc4122(), $actor, 'sales_plan.calendar_created', 'sales_plan.calendar_removed']));
    }

    public function testStrictBodiesNeverClearExistingCalendarAndReadonlyAndStaleAreConflicts(): void
    {
        [$client, $plan] = $this->fixture();
        $url = self::url($plan);
        $entry = ['kind' => 'sales', 'marketplaceSku' => null, 'dateFrom' => '2026-07-01', 'dateTo' => '2026-07-31'];
        self::put($client, $url, ['version' => 1, 'entries' => [$entry]]);
        self::assertSame(200, $client->getResponse()->getStatusCode());
        foreach ([
            '{}', '[]', '{"version":2,"entries":{}}', '{"version":2,"entries":[[]]}', '{"version":2.0,"entries":[]}',
            '{"version":2,"entries":[],"actorUserId":"forged"}',
            json_encode(['version' => 2, 'entries' => [array_replace($entry, ['kind' => 'season_start'])]], \JSON_THROW_ON_ERROR),
            json_encode(['version' => 2, 'entries' => [array_replace($entry, ['dateFrom' => '2026-02-30'])]], \JSON_THROW_ON_ERROR),
            json_encode(['version' => 2, 'entries' => [array_replace($entry, ['dateFrom' => '0000-07-01'])]], \JSON_THROW_ON_ERROR),
            json_encode(['version' => 2, 'entries' => [array_replace($entry, ['dateFrom' => "2026-07-01\0"])]], \JSON_THROW_ON_ERROR),
            json_encode(['version' => 2, 'entries' => [array_replace($entry, ['marketplaceSku' => "bad\0"])]], \JSON_THROW_ON_ERROR),
            json_encode(['version' => 2, 'entries' => [array_replace($entry, ['marketplaceSku' => 'UNKNOWN'])]], \JSON_THROW_ON_ERROR),
            json_encode(['version' => 2, 'entries' => [array_replace($entry, ['id' => Uuid::v7()->toRfc4122()])]], \JSON_THROW_ON_ERROR),
            json_encode(['version' => 2, 'entries' => array_fill(0, 501, $entry)], \JSON_THROW_ON_ERROR),
        ] as $invalid) {
            $client->request('PUT', $url, server: ['CONTENT_TYPE' => 'application/json'], content: $invalid);
            self::assertSame(422, $client->getResponse()->getStatusCode(), $invalid);
            $client->request('GET', $url);
            self::assertSame(2, self::path(self::body($client), 'version'));
            $entries = self::path(self::body($client), 'entries');
            self::assertIsArray($entries);
            self::assertCount(1, $entries);
        }
        foreach ([['limit' => '201'], ['limit' => ['1']], ['limit' => '0'], ['cursor' => 'bad'], ['cursor' => ['bad']], ['marketplaceAccountId' => Uuid::v7()->toRfc4122()]] as $query) {
            $client->request('GET', $url, $query);
            self::assertSame(422, $client->getResponse()->getStatusCode());
        }
        self::put($client, $url, ['version' => 1, 'entries' => []]);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $client->request('POST', substr($url, 0, -9).'/approve', server: ['CONTENT_TYPE' => 'application/json'], content: '{"version":2}');
        self::assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        self::put($client, $url, ['version' => 3, 'entries' => []]);
        self::assertSame(409, $client->getResponse()->getStatusCode());
        $client->request('GET', $url);
        self::assertSame('approved', self::path(self::body($client), 'status'));
    }

    public function testCompanyMembershipAndPlanOwnershipProtectBothMethods(): void
    {
        [$client, $plan] = $this->fixture();
        $foreignCompany = Uuid::v7();
        $plans = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $plans);
        $foreignPlan = SalesPlanBuilder::aSalesPlan()->withCompanyId($foreignCompany)->persistWith($plans);
        foreach ([['/api/companies/'.$foreignCompany->toRfc4122().'/sales-plans/'.$plan->id()->toRfc4122().'/calendar', 403], ['/api/companies/'.$plan->companyId()->toRfc4122().'/sales-plans/'.$foreignPlan->id()->toRfc4122().'/calendar', 404]] as [$url, $status]) {
            $client->request('GET', $url);
            self::assertSame($status, $client->getResponse()->getStatusCode());
            self::put($client, $url, ['version' => 1, 'entries' => []]);
            self::assertSame($status, $client->getResponse()->getStatusCode());
        }
    }

    /** @return array{KernelBrowser, SalesPlan, string} */
    private function fixture(): array
    {
        $client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $users = new DoctrineUserRepository($em);
        $user = UserBuilder::aUser()->persistWith($users);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, new DoctrineCompanyMemberRepository($em));
        $plan = SalesPlanBuilder::aSalesPlan()->withCompanyId($company->id())->withMetric(SalesPlanMetric::RetainedSales)->build();
        $plans = self::getContainer()->get(SalesPlanRepository::class);
        self::assertInstanceOf(SalesPlanRepository::class, $plans);
        $plans->add($plan, SalesPlanLineBuilder::linesOf($plan)->withMarketplaceSku('KNOWN')->build());
        $client->loginUser($user, 'api');

        return [$client, $plan, $user->id()->toRfc4122()];
    }

    private static function url(SalesPlan $plan): string
    {
        return '/api/companies/'.$plan->companyId()->toRfc4122().'/sales-plans/'.$plan->id()->toRfc4122().'/calendar';
    }

    /** @param array<string, mixed> $body */
    private static function put(KernelBrowser $client, string $url, array $body): void
    {
        $client->request('PUT', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
    }

    private static function body(KernelBrowser $client): mixed
    {
        return json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private static function path(mixed $value, string|int ...$keys): mixed
    {
        foreach ($keys as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }
}
