<?php

declare(strict_types=1);

namespace App\Tests\Functional\Advertising;

use App\Advertising\Domain\ObservationPolicyRepository;
use App\Advertising\Infrastructure\Query\ObservationEventsQuery;
use App\Advertising\Infrastructure\Query\ObservationPoliciesQuery;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\ObservationEventBuilder;
use App\Tests\Support\Builder\ObservationPolicyBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\SessionWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Opis\JsonSchema\Validator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class ObservationPoliciesControllerTest extends SessionWebTestCase
{
    private ?string $previous = null;

    protected function setUp(): void
    {
        parent::setUp();
        $old = $_ENV['OZON_ADS_ENABLED'] ?? null;
        self::assertTrue(null === $old || \is_string($old));
        $this->previous = $old;
        $_ENV['OZON_ADS_ENABLED'] = $_SERVER['OZON_ADS_ENABLED'] = '1';
    }

    protected function tearDown(): void
    {
        if (null === $this->previous) {
            unset($_ENV['OZON_ADS_ENABLED'], $_SERVER['OZON_ADS_ENABLED']);
        } else {
            $_ENV['OZON_ADS_ENABLED'] = $_SERVER['OZON_ADS_ENABLED'] = $this->previous;
        } parent::tearDown();
    }

    public function testCreateVersionConflictCompanyScopeAndMoney(): void
    {
        [$client, $company, $account] = $this->fixture();
        $base = '/api/companies/'.$company.'/ozon-ads/policies';
        $input = ['accountId' => $account, 'campaignId' => '49761882', 'dailyLimit' => ['amountMinor' => 10000, 'currency' => 'RUB'], 'start' => '09:00', 'stop' => '21:00', 'pollSeconds' => 600, 'enabled' => true];
        $client->jsonRequest('POST', $base, $input);
        self::assertResponseStatusCodeSame(201);
        $created = $this->json($client);
        self::assertSame('observe', $created['mode']);
        self::assertSame('saved', $created['deliveryStatus']);
        self::assertFalse($created['budgetProtectionActive']);
        self::assertSame($input['dailyLimit'], $created['dailyLimit']);
        $id = $created['id'];
        self::assertIsString($id);
        $update = ['version' => 1, ...array_diff_key($input, ['accountId' => true, 'campaignId' => true])];
        $update['dailyLimit'] = ['amountMinor' => 9000, 'currency' => 'RUB'];
        $client->jsonRequest('PUT', $base.'/'.$id, $update);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json($client)['version']);
        $client->jsonRequest('PUT', $base.'/'.$id, $update);
        self::assertResponseStatusCodeSame(409);
        $client->request('GET', $base);
        self::assertResponseIsSuccessful();
        $items = $this->json($client)['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);
        $client->request('GET', '/api/companies/'.Uuid::v7()->toRfc4122().'/ozon-ads/policies');
        self::assertResponseStatusCodeSame(403);
        $events = '/api/companies/'.$company.'/ozon-ads/events';
        $client->request('GET', $events.'?limit=1&accountId='.$account);
        self::assertResponseIsSuccessful();
        $page = $this->json($client);
        self::assertIsArray($page['items']);
        self::assertCount(1, $page['items']);
        $updated = $page['items'][0] ?? null;
        self::assertIsArray($updated);
        self::assertIsArray($updated['dailyLimit']);
        self::assertSame('policy_updated', $updated['kind']);
        self::assertSame(2, $updated['version']);
        self::assertSame(9000, $updated['dailyLimit']['amountMinor']);
        self::assertIsString($page['nextCursor']);
        $client->request('GET', $events.'?limit=1&cursor='.$page['nextCursor']);
        $older = $this->json($client);
        self::assertIsArray($older['items']);
        self::assertCount(1, $older['items']);
        $createdEvent = $older['items'][0] ?? null;
        self::assertIsArray($createdEvent);
        self::assertIsArray($createdEvent['dailyLimit']);
        self::assertSame('policy_created', $createdEvent['kind']);
        self::assertSame(10000, $createdEvent['dailyLimit']['amountMinor']);
        $client->request('GET', $events.'?accountId='.Uuid::v7()->toRfc4122());
        self::assertSame([], $this->json($client)['items']);
        $client->request('GET', $base.'?enabled=false');
        self::assertSame([], $this->json($client)['items']);
        $client->request('GET', $base.'?enabled=true&accountId='.$account);
        self::assertIsArray($this->json($client)['items']);
        self::assertCount(1, $this->json($client)['items']);
        $client->request('GET', $base.'?enabled=1');
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', '/api/companies/'.$company.'/ozon-ads/availability');
        self::assertSame(['enabled' => true], $this->json($client));
        $client->request('GET', '/api/companies/'.Uuid::v7()->toRfc4122().'/ozon-ads/events');
        self::assertResponseStatusCodeSame(403);
        $input['dailyLimit']['amountMinor'] = 1.5;
        $client->jsonRequest('POST', $base, $input);
        self::assertResponseStatusCodeSame(422);
        $input['dailyLimit']['amountMinor'] = 10000;
        $input['accountId'] = Uuid::v7()->toRfc4122();
        $client->jsonRequest('POST', $base, $input);
        self::assertResponseStatusCodeSame(422);
    }

    public function testServiceContractTokenIsolationAndAcknowledgement(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $policy = ObservationPolicyBuilder::aPolicy()->build();
        $repo = self::getContainer()->get(ObservationPolicyRepository::class);
        self::assertInstanceOf(ObservationPolicyRepository::class, $repo);
        $repo->transactional(static fn () => $repo->add($policy));
        $client->request('GET', '/api/internal/ozon-ads/policies');
        self::assertResponseStatusCodeSame(401);
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer test-internal-service-token-0123456789abcdef'];
        $client->request('GET', '/api/internal/ozon-ads/policies?limit=100', server: $headers);
        self::assertResponseIsSuccessful();
        $raw = $client->getResponse()->getContent();
        self::assertIsString($raw);
        $schemaRaw = file_get_contents('/var/www/services/_contracts/ozon-ads-observe/v1/schema.json');
        self::assertIsString($schemaRaw);
        $schema = json_decode($schemaRaw, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $schema);
        self::assertTrue(new Validator()->validate(json_decode($raw, flags: \JSON_THROW_ON_ERROR), $schema)->isValid(), $raw);
        $at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $day = $at->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
        $body = ['schema_version' => '1', 'company_id' => $policy->companyId()->toRfc4122(), 'account_id' => $policy->accountId()->toRfc4122(), 'policy_id' => $policy->id()->toRfc4122(), 'runtime' => ['day' => $day, 'primary' => ['campaign_id' => '49761882', 'day' => $day, 'spend' => ['amount_minor' => 103, 'currency' => 'RUB'], 'fetched_at' => $at->format(\DATE_RFC3339), 'source' => 'aggregate', 'complete' => true], 'sku' => null, 'effective' => ['amount_minor' => 463, 'currency' => 'RUB'], 'latched' => false, 'decision' => ['action' => 'blocked', 'reason' => 'reserve_unavailable', 'effective_spend' => ['amount_minor' => 463, 'currency' => 'RUB'], 'difference' => ['amount_minor' => 360, 'currency' => 'RUB'], 'reserve' => null, 'source_age_known' => false], 'error' => '', 'applied_version' => 1, 'evaluated_at' => $at->format(\DATE_RFC3339)]];
        $client->jsonRequest('POST', '/api/internal/ozon-ads/observations', $body, server: $headers);
        self::assertResponseIsSuccessful();
        $query = self::getContainer()->get(ObservationPoliciesQuery::class);
        self::assertInstanceOf(ObservationPoliciesQuery::class, $query);
        $viewRow = $query->one($body['company_id'], $body['policy_id'])->executeQuery()->fetchAssociative();
        self::assertIsArray($viewRow);
        $view = ObservationPoliciesQuery::map($viewRow);

        self::assertSame('applied', $view->deliveryStatus);
        self::assertNotNull($view->observation);
        self::assertSame(['amountMinor' => 463, 'currency' => 'RUB'], $view->observation['spend']);
        $eventQuery = self::getContainer()->get(ObservationEventsQuery::class);
        self::assertInstanceOf(ObservationEventsQuery::class, $eventQuery);
        $client->jsonRequest('POST', '/api/internal/ozon-ads/observations', $body, server: $headers);
        self::assertResponseIsSuccessful();
        $journal = $eventQuery->list($body['company_id'], null, 100, null)->executeQuery()->fetchAllAssociative();
        self::assertCount(1, $journal);
        self::assertSame('observation', $journal[0]['kind']);
        self::assertSame(['amountMinor' => 463, 'currency' => 'RUB'], ObservationEventsQuery::map($journal[0])->spend);
        $body['account_id'] = Uuid::v7()->toRfc4122();
        $body['runtime']['effective']['amount_minor'] = 999;
        $body['runtime']['evaluated_at'] = $at->modify('+1 second')->format(\DATE_RFC3339);
        $client->jsonRequest('POST', '/api/internal/ozon-ads/observations', $body, server: $headers);
        self::assertResponseIsSuccessful();
        $unchangedRow = $query->one($body['company_id'], $body['policy_id'])->executeQuery()->fetchAssociative();
        self::assertIsArray($unchangedRow);
        $unchanged = ObservationPoliciesQuery::map($unchangedRow);

        self::assertNotNull($unchanged->observation);
        self::assertSame(['amountMinor' => 463, 'currency' => 'RUB'], $unchanged->observation['spend']);
        self::assertCount(1, $eventQuery->list($body['company_id'], null, 100, null)->executeQuery()->fetchAllAssociative());
        $client->request('GET', '/api/internal/ozon-ads/policies', server: ['HTTP_AUTHORIZATION' => 'Bearer php-to-service-direction-token-0123456789ab']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testDisabledModuleStillReportsAvailabilityAndRejectsReads(): void
    {
        $_ENV['OZON_ADS_ENABLED'] = $_SERVER['OZON_ADS_ENABLED'] = '0';
        [$client, $company] = $this->fixture();
        $root = '/api/companies/'.$company.'/ozon-ads';
        $client->request('GET', $root.'/availability');
        self::assertResponseIsSuccessful();
        self::assertSame(['enabled' => false], $this->json($client));
        foreach (['/policies', '/events'] as $path) {
            $client->request('GET', $root.$path);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testJournalNeverReturnsOtherCompanyEvents(): void
    {
        [$client, $company] = $this->fixture();
        $repo = self::getContainer()->get(ObservationPolicyRepository::class);
        self::assertInstanceOf(ObservationPolicyRepository::class, $repo);
        $foreign = ObservationEventBuilder::anEvent()->build();
        $repo->transactional(static fn () => $repo->recordEvent($foreign));
        $client->request('GET', '/api/companies/'.$company.'/ozon-ads/events');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->json($client)['items']);
    }

    /** @return array{KernelBrowser, string, string} */
    private function fixture(): array
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = self::getContainer();
        $companies = $container->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $users = $container->get(DoctrineUserRepository::class);
        self::assertInstanceOf(DoctrineUserRepository::class, $users);
        $members = $container->get(DoctrineCompanyMemberRepository::class);
        self::assertInstanceOf(DoctrineCompanyMemberRepository::class, $members);
        $accounts = $container->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);
        $cipher = $container->get(MarketplaceCredentialsEncryptor::class);
        self::assertInstanceOf(MarketplaceCredentialsEncryptor::class, $cipher);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $members);
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)->withAdvertisingConnected()->withPlaintextCredentials(['client_id' => 'sandbox', 'api_key' => 'sandbox', 'performance_client_id' => 'sandbox-ads', 'performance_client_secret' => 'sandbox-secret'], $cipher)->persistWith($companies, $accounts);
        $em = $container->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->flush();
        $client->loginUser($user, 'api');

        return [$client, $company->id()->toRfc4122(), $account->id()->toRfc4122()];
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client): array
    {
        $raw = $client->getResponse()->getContent();
        self::assertIsString($raw);
        $value = json_decode($raw, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($value);
        self::assertFalse(array_is_list($value));

        return $value;
    }
}
