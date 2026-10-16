<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplaceDayLoadRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Infrastructure\Query\RecentlyIngestedAccountsQuery;
use App\Tests\Support\Builder\MarketplaceDayLoadBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class RecentlyIngestedAccountsQueryTest extends KernelTestCase
{
    public function testAccountWithARecentDocumentIsListed(): void
    {
        self::bootKernel();

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $this->document($companyId, $accountId, new \DateTimeImmutable('-1 hour'));

        self::assertContains(
            $this->key($companyId, $accountId),
            $this->freshKeys(),
        );
    }

    public function testAccountWhoseLastDocumentIsOldIsNotListed(): void
    {
        self::bootKernel();

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        // Успех есть, но старый: синхронизация когда-то шла и встала.
        // Это и есть случай, ради которого существует оповещение, —
        // подключение живое, экраны рисуются, данные вчерашние.
        $this->document($companyId, $accountId, new \DateTimeImmutable('-3 days'));

        self::assertNotContains(
            $this->key($companyId, $accountId),
            $this->freshKeys(),
        );
    }

    public function testFreshDocumentOfAnotherCompanyDoesNotVouchForOurs(): void
    {
        self::bootKernel();

        // Обязательное покрытие ADR-005: изоляция между компаниями.
        // Здесь она означает не утечку данных, а неверный вывод: если
        // пара «компания + подключение» схлопнется до одного из двух
        // столбцов, чужая исправная синхронизация объявит нашу вставшую
        // свежей, и письма не будет никогда.
        $ours = Uuid::v7();
        $theirs = Uuid::v7();
        $accountId = Uuid::v7();

        $this->document($theirs, $accountId, new \DateTimeImmutable('-1 hour'));
        $this->document($ours, $accountId, new \DateTimeImmutable('-3 days'));

        $fresh = $this->freshKeys();
        self::assertContains($this->key($theirs, $accountId), $fresh);
        self::assertNotContains($this->key($ours, $accountId), $fresh);
    }

    /**
     * @return list<string>
     */
    private function freshKeys(): array
    {
        $query = new RecentlyIngestedAccountsQuery($this->connection());

        $rows = $query->build(new \DateTimeImmutable('-36 hours'), [MarketplaceReportType::OzonPostingFboList])
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static function (array $row): string {
                $fresh = RecentlyIngestedAccountsQuery::mapRow($row);

                return RecentlyIngestedAccountsQuery::key($fresh->companyId, $fresh->marketplaceAccountId, $fresh->reportType);
            },
            $rows,
        );
    }

    private function key(Uuid $companyId, Uuid $accountId): string
    {
        return RecentlyIngestedAccountsQuery::key(
            $companyId->toRfc4122(),
            $accountId->toRfc4122(),
            MarketplaceReportType::OzonPostingFboList,
        );
    }

    /**
     * Успешная загрузка продаж в момент `$at` — отметка дня (ADR-038).
     */
    private function document(Uuid $companyId, Uuid $accountId, \DateTimeImmutable $at): void
    {
        /** @var MarketplaceDayLoadRepository $dayLoads */
        $dayLoads = self::getContainer()->get(MarketplaceDayLoadRepository::class);

        MarketplaceDayLoadBuilder::aMarketplaceDayLoad()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withReportType(MarketplaceReportType::OzonPostingFboList)
            ->withPeriod($at)
            ->withAt($at)
            ->persistWith($dayLoads);
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
