<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Через HTTP проверяется только то, что живёт именно здесь: изоляция
 * арендаторов (обязательное покрытие ADR-005) и отказ на некорректных
 * параметрах. Сам расчёт — уровнем ниже (BuildUnitEconomicsActionTest):
 * денежная арифметика по ADR-005 проверяется отдельно, а тестировать
 * контроллеры §9 запрещает.
 */
final class ShowUnitEconomicsControllerTest extends WebTestCase
{
    public function testDataOfAnotherCompanyIsNotIncluded(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        SalesFactBuilder::aSalesFact()
            ->withBusinessDate($this->today())
            ->withCompanyId($company->id())
            ->withMarketplaceSku('own')
            ->withSourceRowId('own-1')
            ->withStatus('delivered')
            ->persistWith($this->salesFacts());
        // Чужая компания с расходом на тот же артикул — доказывает
        // изоляцию, а не только то, что своё попадает в отчёт.
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withBusinessDate($this->today())
            ->withMarketplaceSku('own')
            ->withAmount(Money::ofMinor(-999_999, 'RUB'))
            ->persistWith($this->expenseFacts());

        $payload = $this->get($client, $company);

        self::assertCount(1, $payload['skus']);
        self::assertSame(0, $payload['skus'][0]['expensesTotalMinor']);
        self::assertSame(0, $payload['cabinetExpensesTotalMinor']);
    }

    public function testWindowBeyondTheLimitIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics?days=400");

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testLimitBeyondTheMaximumIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        // 422, а не тихая обрезка до максимума (§5): клиент, попросивший
        // тысячу строк, должен узнать, что получил не тысячу.
        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics?limit=1000");

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testMalformedCursorIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics?cursor=broken");

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    /**
     * Имя сортировки подставляется в текст SQL, поэтому белый список —
     * не удобство, а граница доверия. Проверяется через HTTP именно
     * поэтому: отсечь значение обязано до запроса, а не в запросе.
     */
    public function testUnknownSortIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics?sort=name");

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testUnknownDirectionIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics?direction=sideways");

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    /**
     * Курсор, снятый при другой сортировке, указывает на другое место.
     * Отдать по нему страницу значило бы показать правдоподобные
     * и неверные цифры — поэтому отказ, а не тихая выдача.
     */
    public function testCursorFromAnotherSortOrderIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        $client->request(
            'GET',
            "/api/companies/{$company->id()->toRfc4122()}/unit-economics?sort=margin&cursor=revenue:desc:100:111",
        );

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    /**
     * @return array{skus: list<array<string, mixed>>, cabinetExpenses: list<array<string, mixed>>, cabinetExpensesTotalMinor: int}
     */
    private function get(KernelBrowser $client, Company $company): array
    {
        $client->catchExceptions(false);
        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{skus: list<array<string, mixed>>, cabinetExpenses: list<array<string, mixed>>, cabinetExpensesTotalMinor: int} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * Бизнес-дата в часовом поясе площадки: окно экрана считается
     * по календарю Ozon, и факт со вчерашней датой по UTC мог бы
     * оказаться вне окна рядом с полуночью.
     */
    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow'));
    }

    private function loginAsCompanyMember(KernelBrowser $client): Company
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users = new DoctrineUserRepository($entityManager);
        $companyMembers = new DoctrineCompanyMemberRepository($entityManager);

        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($this->companies(), $users, $companyMembers);

        $client->loginUser($user, 'api');

        return $company;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return $companies;
    }

    private function salesFacts(): SalesFactRepository
    {
        /** @var SalesFactRepository $salesFacts */
        $salesFacts = static::getContainer()->get(SalesFactRepository::class);

        return $salesFacts;
    }

    private function expenseFacts(): MarketplaceExpenseFactRepository
    {
        /** @var MarketplaceExpenseFactRepository $expenseFacts */
        $expenseFacts = static::getContainer()->get(MarketplaceExpenseFactRepository::class);

        return $expenseFacts;
    }
}
