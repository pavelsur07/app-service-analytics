<?php

declare(strict_types=1);

namespace App\Tests\Integration\ProductResearchOzon;

use App\ProductResearchOzon\Application\SynchronizeCategoriesAction;
use App\ProductResearchOzon\Domain\CatalogProjectionRepository;
use App\ProductResearchOzon\Domain\CatalogSyncFailure;
use App\ProductResearchOzon\Domain\ValueObject\CatalogMetadata;
use App\ProductResearchOzon\Domain\ValueObject\CatalogPage;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use App\ProductResearchOzon\Infrastructure\Query\CatalogQuery;
use App\ProductResearchOzon\Ui\Command\SynchronizeCategoriesCommand;
use App\Tests\Support\Builder\CatalogCategoryEntryBuilder;
use App\Tests\Support\Builder\CatalogProjectionBuilder;
use App\Tests\Support\Fake\ResearchCategorySource;
use App\Tests\Support\Fake\ResearchClock;
use App\Tests\Support\ProductResearchOzon\ResearchServices;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class CatalogProjectionTest extends KernelTestCase
{
    use ResearchServices;

    public function testAllPagesPublishOnceAndFreshCacheAvoidsNetwork(): void
    {
        self::bootKernel();
        $clock = new ResearchClock();
        $clock->freeze('2026-11-01T09:00:00.123456Z');
        $source = new ResearchCategorySource();
        $sourceId = Uuid::v7()->toRfc4122();
        $company = Uuid::v7()->toRfc4122();
        $repo = self::service(CatalogProjectionRepository::class);
        $action = new SynchronizeCategoriesAction($repo, $source, new CatalogContext($sourceId), $clock);
        $source->pages = [new CatalogPage(7, $clock->now(), ['Аксессуары/Хиджабы'], 'next'), new CatalogPage(7, $clock->now(), ['Дом/Категория'], null)];
        self::assertSame('updated', $action($company)->outcome);
        self::assertSame('fresh', $action($company)->outcome);
        self::assertCount(2, $source->requests);
        self::assertSame([['company' => $company, 'cursor' => null], ['company' => $company, 'cursor' => 'next']], $source->requests);
        $metadata = $repo->metadata($company, $sourceId);
        self::assertNotNull($metadata);
        self::assertEquals($clock->now(), $metadata->generatedAt, 'Microseconds of the source timestamp must survive persistence');
        self::assertSame(7, $metadata->revision);
        $clock->freeze('2026-11-02T09:00:00.123455Z');
        self::assertSame('fresh', $action($company)->outcome);
        $clock->freeze('2026-11-02T09:00:00.123456Z');
        $source->pages = [new CatalogPage(8, $clock->now(), [], null)];
        self::assertSame('updated', $action($company)->outcome);
        self::assertSame([], $this->paths($company, $sourceId));
        self::assertCount(3, $source->requests);
    }

    public function testInterruptedMixedAndDuplicatePagesNeverReplacePreviousData(): void
    {
        self::bootKernel();
        $repo = self::service(CatalogProjectionRepository::class);
        $clock = new ResearchClock();
        $clock->freeze('2026-11-01T09:00:00Z');
        $company = Uuid::v7()->toRfc4122();
        $sourceId = Uuid::v7()->toRfc4122();
        $source = new ResearchCategorySource();
        $action = new SynchronizeCategoriesAction($repo, $source, new CatalogContext($sourceId), $clock);
        $source->pages = [new CatalogPage(1, $clock->now(), ['Старая категория'], null)];
        self::assertSame('updated', $action($company)->outcome);
        $clock->freeze('2026-11-02T10:00:00Z');
        $at = $clock->now();
        foreach ([
            [new CatalogPage(2, $at, ['Новая категория'], 'more'), new CatalogSyncFailure('catalog_service_unavailable')],
            [new CatalogPage(2, $at, ['Новая категория'], 'more'), new CatalogPage(3, $at, ['Другая категория'], null)],
            [new CatalogPage(2, $at, ['Новая категория'], 'more'), new CatalogPage(2, $at, ['Новая категория'], null)],
            [new CatalogPage(2, $at, ['Новая категория'], 'more'), new CatalogPage(2, $at, ['Другая категория'], 'more')],
        ] as $pages) {
            self::service(Connection::class)->executeStatement('UPDATE product_research_ozon_catalog SET next_attempt_at=NULL WHERE company_id=:company AND source_connection_id=:source', ['company' => $company, 'source' => $sourceId]);
            $source->pages = $pages;
            self::assertNotSame('updated', $action($company)->outcome);
            self::assertSame(['Старая категория'], $this->paths($company, $sourceId));
            self::assertSame(1, $repo->metadata($company, $sourceId)?->revision);
        }
    }

    public function testSourceScopeAndLostLeaseAreEnforced(): void
    {
        self::bootKernel();
        $repo = self::service(CatalogProjectionRepository::class);
        $company = Uuid::v7()->toRfc4122();
        $connection = Uuid::v7()->toRfc4122();
        $old = Uuid::v7()->toRfc4122();
        $new = Uuid::v7()->toRfc4122();
        $now = new \DateTimeImmutable('2026-11-01T09:00:00Z');
        self::assertTrue($repo->claim($company, $connection, $old, $now));
        self::assertFalse($repo->claim($company, $connection, $new, $now));
        self::assertTrue($repo->claim(Uuid::v7()->toRfc4122(), $connection, $new, $now));
        self::assertTrue($repo->claim($company, Uuid::v7()->toRfc4122(), $new, $now));
        self::service(Connection::class)->executeStatement("UPDATE product_research_ozon_catalog SET lease_until=clock_timestamp()-interval '1 second' WHERE company_id=:company AND source_connection_id=:source", ['company' => $company, 'source' => $connection]);
        self::assertTrue($repo->claim($company, $connection, $new, $now));
        try {
            $repo->publish($company, $connection, $old, new CatalogMetadata(1, $now), ['Не публикуется'], $now);
            self::fail('Expired owner cannot publish');
        } catch (CatalogSyncFailure $failure) {
            self::assertSame('catalog_lease_lost', $failure->getMessage());
        }
        $repo->publish($company, $connection, $new, new CatalogMetadata(1, $now), ['Готовая категория'], $now);
        self::assertSame(['Готовая категория'], $this->paths($company, $connection));
        self::assertNull($repo->metadata(Uuid::v7()->toRfc4122(), $connection));
    }

    public function testBuildersPersistProjectionAndEntryWithRealLookup(): void
    {
        self::bootKernel();
        $em = self::service(EntityManagerInterface::class);
        $catalog = CatalogProjectionBuilder::aCatalog()->persistWith($em);
        CatalogCategoryEntryBuilder::aCategory()->inCatalog($catalog)->persistWith($em);
        self::assertSame(['Аксессуары/Хиджабы и тюрбаны женские'], $this->paths($catalog->companyId()->toRfc4122(), $catalog->sourceId()->toRfc4122()));
    }

    public function testBackgroundCommandIsExplicitlyCompanyScopedAndRepeatIsCached(): void
    {
        self::bootKernel();
        $company = Uuid::v7()->toRfc4122();
        $source = new ResearchCategorySource();
        $clock = new ResearchClock();
        $source->pages = [new CatalogPage(1, $clock->now(), ['Тест/Категория'], null)];
        $action = new SynchronizeCategoriesAction(self::service(CatalogProjectionRepository::class), $source, new CatalogContext(Uuid::v7()->toRfc4122()), $clock);
        $command = new CommandTester(new SynchronizeCategoriesCommand($action));
        self::assertSame(2, $command->execute(['company-id' => 'bad', '--once' => true]));
        self::assertSame([], $source->requests);
        self::assertSame(0, $command->execute(['company-id' => $company, '--once' => true]));
        self::assertStringContainsString('outcome=updated', $command->getDisplay());
        self::assertSame(0, $command->execute(['company-id' => $company, '--once' => true]));
        self::assertStringContainsString('outcome=fresh', $command->getDisplay());
        self::assertCount(1, $source->requests);
    }

    /** @return list<string> */
    private function paths(string $company, string $source): array
    {
        $rows = self::service(CatalogQuery::class)->search($company, $source, '', 200)->executeQuery()->fetchAllAssociative();
        $paths = [];
        foreach ($rows as $row) {
            $path = CatalogQuery::path($row);
            if (null !== $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }
}
