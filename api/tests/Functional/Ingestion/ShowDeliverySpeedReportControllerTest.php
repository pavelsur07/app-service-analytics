<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\DeliverySpeed\DeliverySpeedSkuCursor;
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
 * граница доверия. Расчёт проверяет BuildDeliverySpeedReportActionTest.
 */
final class ShowDeliverySpeedReportControllerTest extends WebTestCase
{
    public function testReportContainsOnlyTheRequestedCompanyData(): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);
        // Окно отчёта заканчивается за 14 дней до сегодня (созревание).
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))->modify('-20 days');

        $this->sale(Uuid::v7(), 'FOREIGN-1', 40, $today);
        $this->sale($companyId, 'OWN-1', 3, $today);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/delivery-speed?days=30');

        self::assertResponseIsSuccessful();
        $payload = $this->payload($client);
        self::assertSame(1, $payload['periodPostings']);
    }

    public function testCompanyMemberCannotReadAnotherCompany(): void
    {
        $client = self::createClient();
        $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.Uuid::v7()->toRfc4122().'/delivery-speed?days=30');

        self::assertResponseStatusCodeSame(403);
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryReturnsStable422(string $query, string $code): void
    {
        $client = self::createClient();
        $companyId = $this->loginAsCompanyMember($client);

        $client->request('GET', '/api/companies/'.$companyId->toRfc4122().'/delivery-speed?'.$query);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($code, $this->payload($client)['code']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidQueries(): iterable
    {
        yield 'days outside the allowed set' => ['days=7', 'invalid_days'];
        yield 'limit above maximum' => ['limit=201', 'invalid_limit'];
        yield 'limit below minimum' => ['limit=0', 'invalid_limit'];
        yield 'garbage cursor' => ['cursor=%%%', 'invalid_cursor'];
        yield 'cursor of another period' => [
            'days=30&cursor='.urlencode((new DeliverySpeedSkuCursor(90, new \DateTimeImmutable('-14 days'), 1, 'SKU', 'Омск'))->encode()),
            'invalid_cursor',
        ];
        yield 'cursor newer than the matured window' => [
            'days=30&cursor='.urlencode((new DeliverySpeedSkuCursor(30, new \DateTimeImmutable('-12 days'), 1, 'SKU', 'Омск'))->encode()),
            'invalid_cursor',
        ];
        yield 'cursor of an old window' => [
            'days=30&cursor='.urlencode((new DeliverySpeedSkuCursor(30, new \DateTimeImmutable('-17 days'), 1, 'SKU', 'Омск'))->encode()),
            'invalid_cursor',
        ];
        yield 'cursor with an impossible date' => [
            'days=30&cursor='.urlencode(base64_encode('[30,"2026-02-30",1,"SKU","Омск"]')),
            'invalid_cursor',
        ];
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
