<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\StockPlacement\StockPlacementCursor;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Через HTTP — только то, что иначе не проверить (CLAUDE.md §9): изоляция
 * компаний живёт в подписчиках kernel.controller, а разбор параметров —
 * граница доверия. Расчёт проверяет BuildStockPlacementReportActionTest.
 */
final class ShowStockPlacementReportControllerTest extends WebTestCase
{
    public function testReportContainsOnlyTheRequestedCompanyData(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);
        // Спрос — продажи по кластеру доставки за 28 дней до сегодня.
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->modify('-3 days');

        $this->sale(Uuid::v7(), 'FOREIGN-1', 40, $today);
        $this->sale($companyId, 'OWN-1', 3, $today);
        // Снимка остатков нет — остаток неизвестен, но спрос виден.

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/stock-placement?days=30');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertIsArray($payload['items']);
        self::assertCount(1, $payload['items']);
        self::assertIsArray($payload['items'][0]);
        self::assertSame(3, $payload['items'][0]['sold']);
    }

    public function testCompanyMemberCannotReadAnotherCompany(): void
    {
        $client = self::createClient();
        $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.Uuid::v7()->toRfc4122().'/stock-placement?days=30');

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryReturnsStable422(string $query, string $code): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/stock-placement?'.$query);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $this->payload($client)['code']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidQueries(): iterable
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        $cursor = static fn (string $day, int $target, int $lead, ?string $status): string => urlencode(
            (new StockPlacementCursor($day, $target, $lead, $status, -1, 0, 'SKU', 'Омск'))->encode(),
        );

        yield 'target below range' => ['target_days=6', 'invalid_target_days'];
        yield 'target above range' => ['target_days=91', 'invalid_target_days'];
        yield 'lead above range' => ['lead_days=61', 'invalid_lead_days'];
        yield 'unknown status' => ['status=everything', 'invalid_status'];
        yield 'limit above maximum' => ['limit=201', 'invalid_limit'];
        yield 'garbage cursor' => ['cursor=%%%', 'invalid_cursor'];
        yield 'cursor of other parameters' => ['target_days=28&lead_days=7&cursor='.$cursor($today, 30, 7, null), 'invalid_cursor'];
        yield 'cursor of another filter' => ['status=deficit&cursor='.$cursor($today, 28, 7, null), 'invalid_cursor'];
        yield 'cursor of an old day' => ['cursor='.$cursor('2026-01-01', 28, 7, null), 'invalid_cursor'];
    }

    private function sale(Uuid $companyId, string $posting, int $quantity, \DateTimeImmutable $date): void
    {
        /** @var SalesFactRepository $repository */
        $repository = self::getContainer()->get(SalesFactRepository::class);
        SalesFactBuilder::aSalesFact()
            ->withCompanyId($companyId)
            ->withSourceRowId("{$posting}|SKU-1")
            ->withPostingNumber($posting)
            ->withMarketplaceSku('SKU-1')
            ->withQuantity($quantity)
            ->withBusinessDate($date->setTime(0, 0))
            ->withOrderedAt($date)
            ->withClusters('Омск', 'Омск')
            ->persistWith($repository);
    }

    private function loginAsCompanyMember(KernelBrowser $client): Uuid
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        $users = new DoctrineUserRepository($entityManager);
        $members = new DoctrineCompanyMemberRepository($entityManager);
        $user = UserBuilder::aUser()->persistWith($users);
        $member = CompanyMemberBuilder::aCompanyMember()->withUser($user)->persistWith($companies, $users, $members);
        $client->loginUser($user, 'api');

        return $member->companyId();
    }

    /** @return array<string, mixed> */
    private function payload(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
