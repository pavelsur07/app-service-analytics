<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\DemandMatrix\DemandMatrixCursor;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use App\Tests\Support\SessionWebTestCase as WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Через HTTP — только то, что иначе не проверить (CLAUDE.md §9): изоляция
 * компаний живёт в подписчиках kernel.controller, а разбор параметров —
 * граница доверия. Расчёт проверяют DemandMatrixQueryTest и
 * BuildDemandMatrixReportActionTest.
 */
final class ShowDemandMatrixReportControllerTest extends WebTestCase
{
    public function testReportContainsOnlyTheRequestedCompanyData(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->modify('-3 days');

        $this->sale(Uuid::v7(), 'FOREIGN-1', 40, $day, 'Казань');
        $this->sale($companyId, 'OWN-1', 3, $day, 'Омск');

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/demand-matrix');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame([['cluster' => 'Омск', 'orders28' => 3]], $payload['clusters']);
        self::assertIsArray($payload['items']);
        self::assertCount(1, $payload['items']);
        self::assertIsArray($payload['items'][0]);
        self::assertSame(3, $payload['items'][0]['orders28']);
        self::assertIsArray($payload['items'][0]['cells']);
        self::assertCount(1, $payload['items'][0]['cells']);
    }

    public function testCompanyMemberCannotReadAnotherCompany(): void
    {
        $client = self::createClient();
        $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.Uuid::v7()->toRfc4122().'/demand-matrix');

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryReturnsStable422(string $query, string $code): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/demand-matrix?'.$query);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $this->payload($client)['code']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidQueries(): iterable
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        $cursor = static fn (string $day, string $search): string => urlencode((new DemandMatrixCursor($day, $search, 5, 'SKU'))->encode());

        yield 'limit zero' => ['limit=0', 'invalid_limit'];
        yield 'limit above maximum' => ['limit=201', 'invalid_limit'];
        yield 'search too long' => ['search='.str_repeat('я', 101), 'invalid_search'];
        yield 'search as array' => ['search[]=a', 'invalid_search'];
        yield 'garbage cursor' => ['cursor=%%%', 'invalid_cursor'];
        yield 'cursor as array' => ['cursor[]=a', 'invalid_cursor'];
        yield 'cursor of another search' => ['search=b&cursor='.$cursor($today, 'a'), 'invalid_cursor'];
        yield 'cursor of an old day' => ['cursor='.$cursor('2026-01-01', ''), 'invalid_cursor'];
    }

    private function sale(Uuid $companyId, string $posting, int $quantity, \DateTimeImmutable $date, string $cluster): void
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
            ->withClusters($cluster, $cluster)
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
