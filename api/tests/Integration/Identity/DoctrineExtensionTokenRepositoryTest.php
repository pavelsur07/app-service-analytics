<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;
use App\Identity\Infrastructure\Repository\DoctrineExtensionTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\ExtensionTokenBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Обязательное покрытие ADR-005: изоляция данных между компаниями.
 */
final class DoctrineExtensionTokenRepositoryTest extends KernelTestCase
{
    public function testTokenOfAnotherCompanyIsNotFoundEvenWithCorrectId(): void
    {
        self::bootKernel();
        [$companies, $users, $tokens] = $this->repositories();

        $ours = CompanyBuilder::aCompany()->withName('Acme LLC')->persistWith($companies);
        $theirs = CompanyBuilder::aCompany()->withName('Other Company')->persistWith($companies);
        $token = ExtensionTokenBuilder::anExtensionToken()->withCompany($ours)->persistWith($companies, $users, $tokens);

        self::assertNotNull($tokens->get($ours->id()->toRfc4122(), $token->id()));
        // Верный id, чужая компания — изоляция держится на SQL, а не
        // на добросовестности вызывающего (CLAUDE.md §1).
        self::assertNull($tokens->get($theirs->id()->toRfc4122(), $token->id()));
    }

    public function testUnknownIdReturnsNull(): void
    {
        self::bootKernel();
        [$companies, , $tokens] = $this->repositories();

        $company = CompanyBuilder::aCompany()->persistWith($companies);

        self::assertNull($tokens->get($company->id()->toRfc4122(), Uuid::v7()));
    }

    public function testSecondRevokeDoesNotOverwriteTheFirstTrace(): void
    {
        self::bootKernel();
        [$companies, $users, $tokens] = $this->repositories();

        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $token = ExtensionTokenBuilder::anExtensionToken()->withCompany($company)->persistWith($companies, $users, $tokens);
        $companyId = $company->id()->toRfc4122();

        $firstActor = Uuid::v7();
        $firstAt = new \DateTimeImmutable('2026-08-12 10:00:00');

        self::assertTrue($tokens->revokeIfActive($companyId, $token->id(), $firstActor, $firstAt));
        // Второй отзыв не меняет ни одной строки: условие revoked_at IS NULL
        // живёт внутри UPDATE, поэтому параллельный запрос не затирает
        // первого отзывающего (ADR-010, след не переписывается).
        self::assertFalse($tokens->revokeIfActive($companyId, $token->id(), Uuid::v7(), new \DateTimeImmutable('2026-08-12 11:00:00')));

        $row = $this->revocationRow($token->id());
        self::assertSame($firstActor->toRfc4122(), $row['revoked_by_user_id']);
        self::assertIsString($row['revoked_at']);
        self::assertStringStartsWith('2026-08-12 10:00:00', $row['revoked_at']);
    }

    public function testRevokeDoesNotReachAnotherCompanysToken(): void
    {
        self::bootKernel();
        [$companies, $users, $tokens] = $this->repositories();

        $ours = CompanyBuilder::aCompany()->persistWith($companies);
        $theirs = CompanyBuilder::aCompany()->persistWith($companies);
        $token = ExtensionTokenBuilder::anExtensionToken()->withCompany($ours)->persistWith($companies, $users, $tokens);

        // companyId внутри UPDATE, а не только в предшествующем чтении.
        self::assertFalse($tokens->revokeIfActive($theirs->id()->toRfc4122(), $token->id(), Uuid::v7(), new \DateTimeImmutable()));
        self::assertNull($this->revocationRow($token->id())['revoked_at']);
    }

    /**
     * @return array<string, mixed>
     */
    private function revocationRow(Uuid $tokenId): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $row = $entityManager->getConnection()->fetchAssociative(
            'SELECT revoked_at, revoked_by_user_id FROM extension_token WHERE id = :id',
            ['id' => $tokenId->toRfc4122()],
        );
        self::assertIsArray($row);

        return $row;
    }

    public function testDuplicateHashIsRejectedByUniqueIndex(): void
    {
        self::bootKernel();
        [$companies, $users, $tokens] = $this->repositories();

        // Уникальный индекс по token_hash — не украшение: на нём стоит
        // однозначность проверки токена.
        $secret = ExtensionTokenSecret::generate();
        ExtensionTokenBuilder::anExtensionToken()->withSecret($secret)->persistWith($companies, $users, $tokens);

        $this->expectException(UniqueConstraintViolationException::class);
        ExtensionTokenBuilder::anExtensionToken()->withSecret($secret)->persistWith($companies, $users, $tokens);
    }

    /**
     * @return array{0: CompanyRepository, 1: DoctrineUserRepository, 2: DoctrineExtensionTokenRepository}
     */
    private function repositories(): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return [
            $companies,
            new DoctrineUserRepository($entityManager),
            new DoctrineExtensionTokenRepository($entityManager),
        ];
    }
}
