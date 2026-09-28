<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\AdSkuExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\OzonFeeTypeNames;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\AdSkuExpenseFactBuilder;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

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

        // Своя продажа — строка выручки ленты начислений (ADR-036).
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withBusinessDate($this->today())
            ->withCompanyId($company->id())
            ->withMarketplaceSku('own')
            ->withAccrualId(1)
            ->withFeeTypeId(OzonFeeTypeNames::REVENUE)
            ->withAmount(Money::ofMinor(240_200, 'RUB'))
            ->persistWith($this->expenseFacts());
        // Чужой возврат на тот же артикул: выручка и возвраты чужой
        // компании в отчёт не попадают.
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withBusinessDate($this->today())
            ->withMarketplaceSku('own')
            ->withAccrualId(2)
            ->withFeeTypeId(OzonFeeTypeNames::REVENUE)
            ->withAmount(Money::ofMinor(-240_200, 'RUB'))
            ->persistWith($this->expenseFacts());
        // Чужая компания с расходом на тот же артикул — доказывает
        // изоляцию, а не только то, что своё попадает в отчёт.
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withBusinessDate($this->today())
            ->withMarketplaceSku('own')
            ->withAmount(Money::ofMinor(-999_999, 'RUB'))
            ->persistWith($this->expenseFacts());
        // И реклама чужой компании (ADR-035): на тот же артикул и
        // «Оплатой за клик» в кабинете — вчерашней, потому что сегодня
        // не сверяется, и под тем же идентификатором подключения, что
        // у нашей компании с подключённой рекламой: пропущенный фильтр
        // компании дал бы нам несошедшийся день.
        $account = Uuid::v7();
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($account)
            ->withReportType(MarketplaceReportType::OzonAdExpense)
            ->withPeriod($this->today())
            ->persistWith($this->rawDocuments());
        AdSkuExpenseFactBuilder::anAdSkuExpenseFact()
            ->withMarketplaceAccountId($account)
            ->withBusinessDate($this->today()->modify('-1 day'))
            ->withMarketplaceSku('own')
            ->withAmount(Money::ofMinor(-888_888, 'RUB'))
            ->persistWith($this->adSkuFacts());
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withMarketplaceAccountId($account)
            ->withBusinessDate($this->today()->modify('-1 day'))
            ->withoutSku()
            ->withFeeTypeId(41)
            ->withUnitNumber('14275771')
            ->withAmount(Money::ofMinor(-777_777, 'RUB'))
            ->persistWith($this->expenseFacts());

        $payload = $this->get($client, $company);

        self::assertCount(1, $payload['skus']);
        self::assertSame(240_200, $payload['skus'][0]['revenueMinor']);
        self::assertSame(0, $payload['skus'][0]['returnedQuantity']);
        self::assertSame(0, $payload['skus'][0]['expensesTotalMinor']);
        self::assertSame(0, $payload['skus'][0]['advertisingMinor']);
        self::assertSame(0, $payload['cabinetExpensesTotalMinor']);
        self::assertSame(0, $payload['advertisingUnreconciledDays']);
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

        // Курсор намеренно правильной формы: иначе тест проходил бы
        // на разборе строки и не проверял бы сверку представления.
        $client->request(
            'GET',
            "/api/companies/{$company->id()->toRfc4122()}/unit-economics?sort=margin&cursor=revenue:desc:30:100:111",
        );

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    /**
     * Значения сортировки посчитаны за окно. Курсор от 90 дней,
     * применённый к 7, отсёк бы почти весь список — страница вышла бы
     * аккуратной и почти пустой, без единого признака ошибки.
     */
    public function testCursorFromAnotherWindowIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        $client->request(
            'GET',
            "/api/companies/{$company->id()->toRfc4122()}/unit-economics?days=7&cursor=revenue:desc:90:100:111",
        );

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    /**
     * @return array{skus: list<array<string, mixed>>, cabinetExpenses: list<array<string, mixed>>, cabinetExpensesTotalMinor: int, advertisingUnreconciledDays: int}
     */
    private function get(KernelBrowser $client, Company $company): array
    {
        $client->catchExceptions(false);
        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{skus: list<array<string, mixed>>, cabinetExpenses: list<array<string, mixed>>, cabinetExpensesTotalMinor: int, advertisingUnreconciledDays: int} $payload */
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

    private function rawDocuments(): MarketplaceRawDocumentRepository
    {
        /** @var MarketplaceRawDocumentRepository $rawDocuments */
        $rawDocuments = static::getContainer()->get(MarketplaceRawDocumentRepository::class);

        return $rawDocuments;
    }

    private function adSkuFacts(): AdSkuExpenseFactRepository
    {
        /** @var AdSkuExpenseFactRepository $facts */
        $facts = static::getContainer()->get(AdSkuExpenseFactRepository::class);

        return $facts;
    }

    private function expenseFacts(): MarketplaceExpenseFactRepository
    {
        /** @var MarketplaceExpenseFactRepository $expenseFacts */
        $expenseFacts = static::getContainer()->get(MarketplaceExpenseFactRepository::class);

        return $expenseFacts;
    }
}
