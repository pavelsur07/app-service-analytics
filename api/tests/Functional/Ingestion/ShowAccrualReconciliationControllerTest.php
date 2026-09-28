<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\OzonFeeTypeNames;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Через HTTP — только изоляция арендаторов (обязательное покрытие
 * ADR-005) и отказ на неверном месяце. Группировка и итоги —
 * BuildAccrualReconciliationActionTest.
 */
final class ShowAccrualReconciliationControllerTest extends WebTestCase
{
    public function testAccrualsOfAnotherCompanyAreNotIncluded(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        $this->row($company, 1, 32, -6_900);
        // Чужая компания с выручкой и расходом в тот же день: пропущенный
        // фильтр компании дал бы нам чужие деньги в итоге к начислению.
        $foreign = CompanyBuilder::aCompany()->persistWith($this->companies());
        $this->row($foreign, 2, OzonFeeTypeNames::REVENUE, 999_900);
        $this->row($foreign, 3, 32, -888_800);

        $client->catchExceptions(false);
        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics/reconciliation?month=2026-08");
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{totalMinor: int, groups: list<array{code: string, totalMinor: int}>} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame(-6_900, $payload['totalMinor']);
        self::assertCount(1, $payload['groups']);
        self::assertSame('delivery', $payload['groups'][0]['code']);
    }

    public function testMalformedMonthIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);

        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics/reconciliation?month=2026-13");

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    public function testFutureMonthIsRejected(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $next = (new \DateTimeImmutable('first day of next month', new \DateTimeZone('Europe/Moscow')))->format('Y-m');

        $client->request('GET', "/api/companies/{$company->id()->toRfc4122()}/unit-economics/reconciliation?month={$next}");

        self::assertSame(422, $client->getResponse()->getStatusCode());
    }

    private function row(Company $company, int $accrualId, int $feeTypeId, int $amountMinor): void
    {
        /** @var MarketplaceExpenseFactRepository $facts */
        $facts = static::getContainer()->get(MarketplaceExpenseFactRepository::class);

        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company->id())
            ->withBusinessDate(new \DateTimeImmutable('2026-08-10'))
            ->withAccrualId($accrualId)
            ->withFeeTypeId($feeTypeId)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->persistWith($facts);
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
}
