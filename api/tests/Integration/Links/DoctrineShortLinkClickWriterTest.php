<?php

declare(strict_types=1);

namespace App\Tests\Integration\Links;

use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Links\Infrastructure\Persistence\DoctrineShortLinkClickWriter;
use App\Links\Infrastructure\Persistence\DoctrineShortLinkRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\ShortLinkBuilder;
use App\Tests\Support\Builder\ShortLinkClickBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineShortLinkClickWriterTest extends KernelTestCase
{
    public function testStoresOneRawClickWithoutAnIpAddress(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $administrator = AdministratorBuilder::anAdministrator()->persistWith(
            new DoctrineAdministratorRepository($entityManager),
        );
        $links = new DoctrineShortLinkRepository($entityManager);
        $link = ShortLinkBuilder::aShortLink()
            ->withCreatedByAdminId($administrator->id())
            ->persistWith($links);
        $writer = new DoctrineShortLinkClickWriter($this->connection());

        ShortLinkClickBuilder::aClick()
            ->forLink($link)
            ->withClickedAt(new \DateTimeImmutable('2026-09-03 10:15:00 UTC'))
            ->withUserAgent('Mozilla/5.0 Chrome/130.0 Safari/537.36')
            ->withReferer('https://example.com/newsletter')
            ->asBot(false)
            ->persistWith($writer);

        $row = $this->connection()->fetchAssociative(
            'SELECT short_link_id, clicked_at, user_agent, referer, is_bot FROM short_link_click WHERE short_link_id = ?',
            [$link->id()->toRfc4122()],
        );

        self::assertIsArray($row);
        self::assertSame($link->id()->toRfc4122(), $row['short_link_id']);
        self::assertSame('2026-09-03 10:15:00', $row['clicked_at']);
        self::assertSame('Mozilla/5.0 Chrome/130.0 Safari/537.36', $row['user_agent']);
        self::assertSame('https://example.com/newsletter', $row['referer']);
        self::assertFalse($row['is_bot']);

        $columns = $this->connection()->fetchFirstColumn(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'short_link_click' ORDER BY ordinal_position",
        );
        self::assertNotContains('ip', $columns);
        self::assertNotContains('ip_address', $columns);
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
