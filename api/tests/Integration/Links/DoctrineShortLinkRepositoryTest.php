<?php

declare(strict_types=1);

namespace App\Tests\Integration\Links;

use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Links\Infrastructure\Persistence\DoctrineShortLinkRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\ShortLinkBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineShortLinkRepositoryTest extends KernelTestCase
{
    public function testStoresLoadsAndRefusesAnOccupiedCode(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $administrators = new DoctrineAdministratorRepository($entityManager);
        $administrator = AdministratorBuilder::anAdministrator()->persistWith($administrators);
        $repository = new DoctrineShortLinkRepository($entityManager);

        $first = ShortLinkBuilder::aShortLink()
            ->withCode('AbC0123')
            ->withCreatedByAdminId($administrator->id())
            ->build();

        self::assertTrue($repository->tryAdd($first));
        self::assertFalse($repository->tryAdd(
            ShortLinkBuilder::aShortLink()
                ->withCode('AbC0123')
                ->withCreatedByAdminId($administrator->id())
                ->build(),
        ));

        $loaded = $repository->get($first->id());

        self::assertNotNull($loaded);
        self::assertSame('AbC0123', $loaded->code());
        self::assertSame($administrator->id()->toRfc4122(), $loaded->createdByAdminId()->toRfc4122());
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
