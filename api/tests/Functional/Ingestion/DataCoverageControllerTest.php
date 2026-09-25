<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Отчёт о полноте данных через HTTP: изоляция компаний (членство проверяет
 * подписчик kernel.controller, принадлежность кабинета — сценарий) и разбор
 * месяца на границе доверия. Правила покрытия проверяет unit-тест расчёта.
 */
final class DataCoverageControllerTest extends WebTestCase
{
    public function testCabinetOfAnotherCompanyIsNotFound(): void
    {
        $client = static::createClient();
        $foreign = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($this->companies()))
            ->withExternalShopId('shop-foreign')
            ->persistWith($this->companies(), $this->accounts());
        $company = $this->loginAsCompanyMember($client);

        // Своя компания в пути, чужой кабинет — 404, а не отчёт по чужим данным.
        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/connections/{$foreign->id()->toRfc4122()}/coverage");

        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testOwnCabinetGetsDaysOfTheMonthWithoutAdvertisingRows(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->account($company, advertising: false);
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($account->id())
            ->withReportType(MarketplaceReportType::OzonPostingFboList)
            ->withPeriod(new \DateTimeImmutable('2026-08-03'))
            ->persistWith(MarketplaceRawDocumentBuilder::repository(static::getContainer()));

        $payload = $this->coverage($client, $company, $account, '2026-08');

        self::assertSame('2026-08', $payload['month']);
        self::assertCount(31, $payload['days']);
        // Реклама — только у кабинета с рекламным ключом.
        self::assertSame(
            [MarketplaceReportType::OzonPostingFboList, MarketplaceReportType::OzonAccrualByDay, MarketplaceReportType::OzonReturnsList, MarketplaceReportType::OzonProductList, MarketplaceReportType::OzonProductInfoList],
            array_column($payload['rows'], 'key'),
        );
        self::assertSame('loaded', $payload['rows'][0]['statuses'][2]);
        self::assertSame('missing', $payload['rows'][0]['statuses'][3]);
        self::assertSame('total', $payload['total']['key']);
    }

    public function testCabinetWithAdvertisingKeyGetsAdvertisingRows(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->account($company, advertising: true);

        $keys = array_column($this->coverage($client, $company, $account, '2026-08')['rows'], 'key');

        self::assertContains(MarketplaceReportType::OzonAdExpense, $keys);
        self::assertContains(MarketplaceReportType::OzonAdCpoOrders, $keys);
    }

    public function testLoadLyingInFailedIsAnErrorOnlyForItsCabinet(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->account($company, advertising: false);
        $other = $this->account($company, advertising: false);
        $this->failed(new FetchOzonExpensesMessage($company->id()->toRfc4122(), $account->id()->toRfc4122(), '2026-08-05'));

        $expenses = $this->coverage($client, $company, $account, '2026-08')['rows'][1];
        $otherExpenses = $this->coverage($client, $company, $other, '2026-08')['rows'][1];

        self::assertSame('failed', $expenses['statuses'][4]);
        self::assertSame('missing', $otherExpenses['statuses'][4]);
    }

    public function testFailedLoadOfAnotherCompanyIsNotShown(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->account($company, advertising: false);
        // Тот же кабинет, но сообщение чужой компании: сверка идёт по обоим.
        $this->failed(new FetchOzonExpensesMessage('019fe6ea-0000-7000-8000-00000000c0de', $account->id()->toRfc4122(), '2026-08-05'));

        self::assertSame('missing', $this->coverage($client, $company, $account, '2026-08')['rows'][1]['statuses'][4]);
    }

    public function testMonthIsParsedStrictly(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->account($company, advertising: false);
        $base = "/api/companies/{$company->id()->toRfc4122()}/connections/{$account->id()->toRfc4122()}/coverage";

        foreach (['2026-13', 'сентябрь', '2019-12', (new \DateTimeImmutable('+2 months'))->format('Y-m')] as $month) {
            $client->request('GET', $base.'?month='.urlencode($month));
            self::assertSame(422, $client->getResponse()->getStatusCode(), $month);
        }
    }

    /**
     * @return array{month: string, days: list<string>, total: array<string, mixed>, rows: list<array{key: string, statuses: list<string>}>}
     */
    private function coverage(KernelBrowser $client, Company $company, MarketplaceAccount $account, string $month): array
    {
        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/connections/{$account->id()->toRfc4122()}/coverage?month={$month}");
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{month: string, days: list<string>, total: array<string, mixed>, rows: list<array{key: string, statuses: list<string>}>} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * Сообщение в том виде, в каком его кладёт в `failed` очередь.
     */
    private function failed(object $message): void
    {
        $encoded = (new PhpSerializer())->encode(new Envelope($message));
        $connection = static::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->insert('messenger_messages', [
            'body' => $encoded['body'],
            'headers' => json_encode($encoded['headers'] ?? [], \JSON_THROW_ON_ERROR),
            'queue_name' => 'failed',
            'created_at' => '2026-08-05 10:00:00',
            'available_at' => '2026-08-05 10:00:00',
        ]);
    }

    private function account(Company $company, bool $advertising): MarketplaceAccount
    {
        $builder = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)));
        if ($advertising) {
            $builder = $builder->withAdvertisingConnected();
        }

        return $builder->persistWith($this->companies(), $this->accounts());
    }

    private function loginAsCompanyMember(KernelBrowser $client): Company
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users = new DoctrineUserRepository($entityManager);
        $members = new DoctrineCompanyMemberRepository($entityManager);

        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($this->companies(), $users, $members);
        $client->loginUser($user, 'api');

        return $company;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return $companies;
    }

    private function accounts(): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);

        return $accounts;
    }
}
