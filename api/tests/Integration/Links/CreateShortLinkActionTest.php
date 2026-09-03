<?php

declare(strict_types=1);

namespace App\Tests\Integration\Links;

use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Links\Application\CreateShortLinkAction;
use App\Links\Application\ShortCodeGenerationFailed;
use App\Links\Domain\ShortCodeGenerator;
use App\Links\Infrastructure\Persistence\DoctrineShortLinkRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\ShortLinkBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateShortLinkActionTest extends KernelTestCase
{
    public function testRetriesOccupiedCodesAndStoresTheFirstFreeCode(): void
    {
        self::bootKernel();
        [$repository, $adminId] = $this->repositoryAndAdmin();
        ShortLinkBuilder::aShortLink()->withCode('Taken01')->withCreatedByAdminId($adminId)->persistWith($repository);
        ShortLinkBuilder::aShortLink()->withCode('Taken02')->withCreatedByAdminId($adminId)->persistWith($repository);
        $codes = new SequenceShortCodeGenerator(['Taken01', 'Taken02', 'Fresh03']);
        $create = new CreateShortLinkAction($repository, $codes);

        $created = $create('Campaign', 'https://example.com/new', $adminId->toRfc4122());

        self::assertSame('Fresh03', $created->code());
        self::assertSame('Fresh03', $repository->get($created->id())?->code());
        self::assertSame(3, $codes->calls);
    }

    public function testStopsAfterFiveOccupiedCodes(): void
    {
        self::bootKernel();
        [$repository, $adminId] = $this->repositoryAndAdmin();
        $occupied = ['Used001', 'Used002', 'Used003', 'Used004', 'Used005'];
        foreach ($occupied as $code) {
            ShortLinkBuilder::aShortLink()->withCode($code)->withCreatedByAdminId($adminId)->persistWith($repository);
        }
        $codes = new SequenceShortCodeGenerator($occupied);
        $create = new CreateShortLinkAction($repository, $codes);

        try {
            $create('Campaign', 'https://example.com/new', $adminId->toRfc4122());
            self::fail('Five occupied codes must fail creation.');
        } catch (ShortCodeGenerationFailed) {
            self::assertSame(5, $codes->calls);
        }
    }

    /**
     * @return array{DoctrineShortLinkRepository, \Symfony\Component\Uid\Uuid}
     */
    private function repositoryAndAdmin(): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $administrator = AdministratorBuilder::anAdministrator()->persistWith(
            new DoctrineAdministratorRepository($entityManager),
        );

        return [new DoctrineShortLinkRepository($entityManager), $administrator->id()];
    }
}

final class SequenceShortCodeGenerator implements ShortCodeGenerator
{
    public int $calls = 0;

    /**
     * @param list<string> $codes
     */
    public function __construct(private readonly array $codes)
    {
    }

    public function generate(): string
    {
        $code = $this->codes[$this->calls] ?? null;
        ++$this->calls;

        if (null === $code) {
            throw new \LogicException('Test code sequence is exhausted.');
        }

        return $code;
    }
}
