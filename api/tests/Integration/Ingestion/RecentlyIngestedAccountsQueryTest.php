<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceRawDocumentRepository;
use App\Ingestion\Infrastructure\Query\RecentlyIngestedAccountsQuery;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
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
            RecentlyIngestedAccountsQuery::key($companyId->toRfc4122(), $accountId->toRfc4122()),
            $this->freshKeys(),
        );
    }

    public function testAccountWhoseLastDocumentIsOldIsNotListed(): void
    {
        self::bootKernel();

        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        // Документ есть, но старый: синхронизация когда-то шла и встала.
        // Это и есть случай, ради которого существует оповещение, —
        // подключение живое, экраны рисуются, данные вчерашние.
        $this->document($companyId, $accountId, new \DateTimeImmutable('-3 days'));

        self::assertNotContains(
            RecentlyIngestedAccountsQuery::key($companyId->toRfc4122(), $accountId->toRfc4122()),
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
        self::assertContains(RecentlyIngestedAccountsQuery::key($theirs->toRfc4122(), $accountId->toRfc4122()), $fresh);
        self::assertNotContains(RecentlyIngestedAccountsQuery::key($ours->toRfc4122(), $accountId->toRfc4122()), $fresh);
    }

    /**
     * @return list<string>
     */
    private function freshKeys(): array
    {
        $query = new RecentlyIngestedAccountsQuery($this->connection());

        $rows = $query->build(new \DateTimeImmutable('-36 hours'))->executeQuery()->fetchAllAssociative();

        return array_map(
            static function (array $row): string {
                $fresh = RecentlyIngestedAccountsQuery::mapRow($row);

                return RecentlyIngestedAccountsQuery::key($fresh->companyId, $fresh->marketplaceAccountId);
            },
            $rows,
        );
    }

    private function document(Uuid $companyId, Uuid $accountId, \DateTimeImmutable $receivedAt): void
    {
        // Не $container->get() конкретного класса: private-сервис без
        // потребителя вычищается компилятором контейнера (та же причина,
        // что в DoctrineMarketplaceRawDocumentRepositoryTest).
        $repository = new DoctrineMarketplaceRawDocumentRepository($this->connection());

        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($companyId)
            ->withMarketplaceAccountId($accountId)
            ->withReceivedAt($receivedAt)
            ->persistWith($repository);
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
