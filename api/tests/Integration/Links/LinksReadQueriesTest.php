<?php

declare(strict_types=1);

namespace App\Tests\Integration\Links;

use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Links\Application\BuildMonthlyClicksAction;
use App\Links\Infrastructure\Persistence\DoctrineShortLinkClickWriter;
use App\Links\Infrastructure\Persistence\DoctrineShortLinkRepository;
use App\Links\Infrastructure\Query\AllShortLinksForAdminQuery;
use App\Links\Infrastructure\Query\MonthlyClicksQuery;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\ShortLinkBuilder;
use App\Tests\Support\Builder\ShortLinkClickBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class LinksReadQueriesTest extends KernelTestCase
{
    public function testAdminListIsStablePaginatedAndUsesReadonlyRows(): void
    {
        self::bootKernel();
        [$links, $adminId] = $this->linksAndAdmin();
        $older = ShortLinkBuilder::aShortLink()
            ->withCode('Link001')
            ->withName('Older')
            ->withCreatedAt(new \DateTimeImmutable('2026-09-01 09:00:00 UTC'))
            ->withCreatedByAdminId($adminId)
            ->persistWith($links);
        $newer = ShortLinkBuilder::aShortLink()
            ->withCode('Link002')
            ->withName('Newer')
            ->withCreatedAt(new \DateTimeImmutable('2026-09-02 09:00:00 UTC'))
            ->withCreatedByAdminId($adminId)
            ->persistWith($links);
        $query = new AllShortLinksForAdminQuery($this->connection());

        $raw = $query->build()
            ->setFirstResult(0)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAllAssociative();
        $firstPage = array_map(AllShortLinksForAdminQuery::mapRow(...), $raw);

        self::assertSame(2, $query->countAll());
        self::assertCount(1, $firstPage);
        self::assertSame($newer->id()->toRfc4122(), $firstPage[0]->id);
        self::assertSame('Newer', $firstPage[0]->name);
        self::assertSame(1, $firstPage[0]->version);
        self::assertNotSame($older->id()->toRfc4122(), $firstPage[0]->id);
    }

    public function testMonthlyResultFillsZeroDaysAndExcludesBots(): void
    {
        self::bootKernel();
        [$links, $adminId] = $this->linksAndAdmin();
        $link = ShortLinkBuilder::aShortLink()
            ->withCreatedByAdminId($adminId)
            ->persistWith($links);
        $writer = new DoctrineShortLinkClickWriter($this->connection());

        ShortLinkClickBuilder::aClick()
            ->forLink($link)
            ->withClickedAt(new \DateTimeImmutable('2026-09-01 10:00:00 UTC'))
            ->asBot(false)
            ->persistWith($writer);
        ShortLinkClickBuilder::aClick()
            ->forLink($link)
            ->withClickedAt(new \DateTimeImmutable('2026-09-01 11:00:00 UTC'))
            ->asBot(false)
            ->persistWith($writer);
        ShortLinkClickBuilder::aClick()
            ->forLink($link)
            ->withClickedAt(new \DateTimeImmutable('2026-09-02 11:00:00 UTC'))
            ->asBot(true)
            ->persistWith($writer);
        ShortLinkClickBuilder::aClick()
            ->forLink($link)
            ->withClickedAt(new \DateTimeImmutable('2026-09-03 08:00:00 UTC'))
            ->asBot(false)
            ->persistWith($writer);
        $build = new BuildMonthlyClicksAction(new MonthlyClicksQuery($this->connection()));

        $result = $build(
            $link->id()->toRfc4122(),
            '2026-09',
            new \DateTimeImmutable('2026-09-03 12:00:00 UTC'),
        );

        self::assertNotNull($result);
        self::assertSame('2026-09', $result->month);
        self::assertSame([
            ['date' => '2026-09-01', 'clicks' => 2],
            ['date' => '2026-09-02', 'clicks' => 0],
            ['date' => '2026-09-03', 'clicks' => 1],
        ], $result->items);
    }

    public function testMonthlyResultReturnsNullForUnknownLink(): void
    {
        self::bootKernel();
        $build = new BuildMonthlyClicksAction(new MonthlyClicksQuery($this->connection()));

        self::assertNull($build(
            Uuid::v7()->toRfc4122(),
            '2026-09',
            new \DateTimeImmutable('2026-09-03 12:00:00 UTC'),
        ));
    }

    /**
     * @return array{DoctrineShortLinkRepository, Uuid}
     */
    private function linksAndAdmin(): array
    {
        $entityManager = $this->entityManager();
        $administrator = AdministratorBuilder::anAdministrator()->persistWith(
            new DoctrineAdministratorRepository($entityManager),
        );

        return [new DoctrineShortLinkRepository($entityManager), $administrator->id()];
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}
