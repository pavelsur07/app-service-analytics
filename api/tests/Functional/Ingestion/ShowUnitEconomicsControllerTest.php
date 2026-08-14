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
 * Юнит-экономика: расходы по товару складываются с выручкой, расходы
 * кабинета показываются отдельно. Обязательное покрытие ADR-005 —
 * изоляция данных между компаниями.
 */
final class ShowUnitEconomicsControllerTest extends WebTestCase
{
    public function testMarginIsRevenueMinusCommissionAndExpenses(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        SalesFactBuilder::aSalesFact()
            ->withBusinessDate($this->today())
            ->withCompanyId($company->id())
            ->withMarketplaceSku('111')
            ->withSourceRowId('sale-1')
            ->withStatus('delivered')
            ->withAmount(Money::ofMinor(274_700, 'RUB'))
            ->withCommissionAmount(Money::ofMinor(-126_362, 'RUB'))
            ->persistWith($this->salesFacts());

        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withBusinessDate($this->today())
            ->withCompanyId($company->id())
            ->withMarketplaceSku('111')
            ->withFeeTypeId(32)
            ->withAmount(Money::ofMinor(-6_900, 'RUB'))
            ->persistWith($this->expenseFacts());

        $payload = $this->get($client, $company);

        self::assertCount(1, $payload['skus']);
        $sku = $payload['skus'][0];
        self::assertSame('111', $sku['marketplaceSku']);
        self::assertSame(274_700, $sku['revenueMinor']);
        self::assertSame(-126_362, $sku['commissionMinor']);
        self::assertSame(-6_900, $sku['expensesTotalMinor']);
        // Сложение, а не вычитание: комиссия и расходы приходят
        // от площадки отрицательными, и «взять по модулю» здесь означало
        // бы сложить расход с выручкой.
        self::assertSame(274_700 - 126_362 - 6_900, $sku['marginMinor']);
        // Название расхода приходит из снимка справочника площадки:
        // клиенту нужен «Логистика», а не «тип 32».
        $expenses = $sku['expenses'];
        self::assertIsArray($expenses);
        $first = $expenses[0];
        self::assertIsArray($first);
        self::assertSame('Логистика', $first['name']);
    }

    public function testCabinetExpensesAreShownApartFromProducts(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withBusinessDate($this->today())
            ->withCompanyId($company->id())
            ->withoutSku()
            ->withFeeTypeId(41)
            ->withAmount(Money::ofMinor(-23_793, 'RUB'))
            ->persistWith($this->expenseFacts());

        $payload = $this->get($client, $company);

        // Реклама и хранение не размазываются по товарам (ADR-012):
        // базис распределения захочется менять, а показанная строка
        // честнее доли, происхождение которой клиент не проверит.
        self::assertSame([], $payload['skus']);
        self::assertSame(-23_793, $payload['cabinetExpensesTotalMinor']);
        self::assertSame('Оплата за клик', $payload['cabinetExpenses'][0]['name']);
        self::assertSame(41, $payload['cabinetExpenses'][0]['feeTypeId']);
    }

    public function testProductWithExpensesButNoSalesIsStillVisible(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        // Возврат обработали в этом периоде, а продан товар был в прошлом.
        // Спрятать такой расход значило бы занизить издержки.
        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withBusinessDate($this->today())
            ->withCompanyId($company->id())
            ->withMarketplaceSku('222')
            ->withFeeTypeId(59)
            ->withAmount(Money::ofMinor(-11_500, 'RUB'))
            ->persistWith($this->expenseFacts());

        $payload = $this->get($client, $company);

        self::assertCount(1, $payload['skus']);
        self::assertSame('222', $payload['skus'][0]['marketplaceSku']);
        self::assertSame(0, $payload['skus'][0]['revenueMinor']);
        self::assertSame(-11_500, $payload['skus'][0]['marginMinor']);
    }

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
