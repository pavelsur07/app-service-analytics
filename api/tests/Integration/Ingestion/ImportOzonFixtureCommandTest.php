<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Ui\Command\ImportOzonFixtureCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class ImportOzonFixtureCommandTest extends KernelTestCase
{
    private const string FIXTURE = __DIR__.'/../../Fixtures/Marketplace/ozon/ozon-buyout-posting-statuses-after.json';

    public function testFixtureTravelsThroughRawStatusAndSalesFactsIdempotently(): void
    {
        self::bootKernel();
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        /** @var ImportOzonFixtureCommand $command */
        $command = self::getContainer()->get(ImportOzonFixtureCommand::class);
        $tester = new CommandTester($command);
        $arguments = [
            'companyId' => $companyId->toRfc4122(),
            'marketplaceAccountId' => $accountId->toRfc4122(),
            'businessDate' => '2026-08-01',
            'fixturePath' => self::FIXTURE,
        ];

        self::assertSame(0, $tester->execute($arguments));
        self::assertSame(0, $tester->execute($arguments));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertEquals(1, $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE company_id = ?',
            [$companyId->toRfc4122()],
        ));
        self::assertEquals(7, $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_posting_status WHERE company_id = ?',
            [$companyId->toRfc4122()],
        ));
        self::assertEquals(7, $connection->fetchOne(
            'SELECT COUNT(*) FROM sales_fact WHERE company_id = ?',
            [$companyId->toRfc4122()],
        ));
    }
}
