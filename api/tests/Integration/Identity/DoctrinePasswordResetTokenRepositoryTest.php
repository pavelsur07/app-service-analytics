<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\IssueExtensionTokenAction;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\PasswordResetSecret;
use App\Identity\Infrastructure\Repository\DoctrineExtensionTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrinePasswordResetTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\ExtensionTokenBuilder;
use App\Tests\Support\Builder\PasswordResetTokenBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-037: ссылка одноразовая, живёт час, последняя отменяет прежние,
 * а смена пароля и погашение — одна транзакция.
 */
final class DoctrinePasswordResetTokenRepositoryTest extends KernelTestCase
{
    private const string NEW_HASH = 'new-password-hash';

    public function testValidTokenChangesPasswordOnceAndWritesOneAuditRecord(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-10-15 12:00:00');
        $secret = PasswordResetSecret::generate();
        $user = $this->userWithToken($secret, $now->modify('-10 minutes'));
        $repository = $this->repository();

        self::assertSame($user->email(), $repository->resetPassword($secret->hash(), self::NEW_HASH, $now));
        self::assertNull(
            $repository->resetPassword($secret->hash(), 'second-hash', $now->modify('+1 second')),
            'повтор той же ссылки пароль не меняет',
        );

        self::assertSame(self::NEW_HASH, $this->storedPasswordHash($user));
        $audit = $this->auditRows($user);
        self::assertCount(1, $audit);
        self::assertNull($audit[0]['company_id']);
        self::assertSame($user->id()->toRfc4122(), $audit[0]['subject_id']);
        self::assertNull($audit[0]['previous_value']);
        self::assertNull($audit[0]['new_value']);
    }

    public function testResetRevokesAllUserTokensAcrossCompaniesAndPreservesOtherUsersAndRevocationTrace(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-10-25 12:00:00');
        $secret = PasswordResetSecret::generate();
        $user = $this->userWithToken($secret, $now->modify('-1 minute'));
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $users = new DoctrineUserRepository($this->entityManager());
        $tokens = new DoctrineExtensionTokenRepository($this->entityManager());
        $first = CompanyBuilder::aCompany()->persistWith($companies);
        $second = CompanyBuilder::aCompany()->persistWith($companies);
        $firstToken = ExtensionTokenBuilder::anExtensionToken()->withUser($user)->withCompany($first)
            ->persistWith($companies, $users, $tokens);
        $secondToken = ExtensionTokenBuilder::anExtensionToken()->withUser($user)->withCompany($second)
            ->persistWith($companies, $users, $tokens);
        $otherToken = ExtensionTokenBuilder::anExtensionToken()->withCompany($first)
            ->persistWith($companies, $users, $tokens);
        $revoked = ExtensionTokenBuilder::anExtensionToken()->withUser($user)->withCompany($first)
            ->persistWith($companies, $users, $tokens);
        $previousActor = $otherToken->userId();
        $previousAt = $now->modify('-1 day');
        $tokens->revokeIfActive($first->id()->toRfc4122(), $revoked->id(), $previousActor, $previousAt);

        self::assertSame($user->email(), $this->repository()->resetPassword($secret->hash(), self::NEW_HASH, $now));

        foreach ([$firstToken, $secondToken] as $token) {
            $row = $this->connection()->fetchAssociative(
                'SELECT revoked_at, revoked_by_user_id FROM extension_token WHERE id = :id',
                ['id' => $token->id()->toRfc4122()],
            );
            self::assertIsArray($row);
            self::assertSame('2026-10-25 12:00:00', $row['revoked_at']);
            self::assertSame($user->id()->toRfc4122(), $row['revoked_by_user_id']);
        }
        self::assertNull($this->connection()->fetchOne(
            'SELECT revoked_at FROM extension_token WHERE id = :id', ['id' => $otherToken->id()->toRfc4122()],
        ));
        $trace = $this->connection()->fetchAssociative(
            'SELECT revoked_at, revoked_by_user_id FROM extension_token WHERE id = :id',
            ['id' => $revoked->id()->toRfc4122()],
        );
        self::assertIsArray($trace);
        self::assertSame('2026-10-24 12:00:00', $trace['revoked_at']);
        self::assertSame($previousActor->toRfc4122(), $trace['revoked_by_user_id']);
    }

    public function testRequestAuthenticatedBeforeResetCannotIssueTokenAfterReset(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable();
        $secret = PasswordResetSecret::generate();
        $user = $this->userWithToken($secret, $now->modify('-1 minute'));
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $action = new IssueExtensionTokenAction(new DoctrineExtensionTokenRepository($this->entityManager()));

        self::assertNotNull($this->repository()->resetPassword($secret->hash(), self::NEW_HASH, $now));

        self::assertNull($action($company->id(), $user->id(), 'original-hash'));
        self::assertNotNull($action($company->id(), $user->id(), self::NEW_HASH));
    }

    public function testExtensionIssueWaitsForResetLock(): void
    {
        self::bootKernel();
        $secret = PasswordResetSecret::generate();
        $user = $this->userWithToken($secret, new \DateTimeImmutable('-1 minute'));
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $resetter = $this->independentConnection();
        $resetter->beginTransaction();
        $resetter->executeStatement(
            "SELECT pg_advisory_xact_lock(hashtextextended('conwix.identity.password-reset:' || :user_id, 0))",
            ['user_id' => $user->id()->toRfc4122()],
        );

        try {
            $this->connection()->executeStatement("SET LOCAL lock_timeout = '100ms'");
            $this->expectException(DriverException::class);
            $action = new IssueExtensionTokenAction(new DoctrineExtensionTokenRepository($this->entityManager()));
            $action($company->id(), $user->id(), 'original-hash');
        } finally {
            $resetter->rollBack();
            $resetter->close();
        }
    }

    public function testAuditFailureRollsBackPasswordResetLinkAndExtensionRevocation(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable();
        $secret = PasswordResetSecret::generate();
        $user = $this->userWithToken($secret, $now->modify('-1 minute'));
        $companies = self::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $token = ExtensionTokenBuilder::anExtensionToken()->withUser($user)->persistWith(
            $companies,
            new DoctrineUserRepository($this->entityManager()),
            new DoctrineExtensionTokenRepository($this->entityManager()),
        );
        $this->connection()->executeStatement(
            "ALTER TABLE audit_record ADD CONSTRAINT test_reject_password_reset CHECK (action <> 'user.password_reset') NOT VALID",
        );

        try {
            $this->repository()->resetPassword($secret->hash(), self::NEW_HASH, $now);
            self::fail('The audit constraint must reject the reset.');
        } catch (DriverException) {
            self::assertSame('original-hash', $this->storedPasswordHash($user));
            self::assertNull($this->storedConsumedAt($secret));
            self::assertNull($this->connection()->fetchOne(
                'SELECT revoked_at FROM extension_token WHERE id = :id',
                ['id' => $token->id()->toRfc4122()],
            ));
        }
    }

    public function testExpiredAndUnknownTokensChangeNothing(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-10-15 12:00:00');
        $expired = PasswordResetSecret::generate();
        $user = $this->userWithToken($expired, $now->modify('-60 minutes'));
        $repository = $this->repository();

        self::assertNull($repository->resetPassword($expired->hash(), self::NEW_HASH, $now));
        self::assertNull($repository->resetPassword(PasswordResetSecret::generate()->hash(), self::NEW_HASH, $now));

        self::assertSame('original-hash', $this->storedPasswordHash($user));
        self::assertNull($this->storedConsumedAt($expired));
        self::assertCount(0, $this->auditRows($user));
    }

    public function testNewLinkRevokesPreviousOne(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-10-15 12:00:00');
        $first = PasswordResetSecret::generate();
        $second = PasswordResetSecret::generate();
        $user = $this->userWithToken($first, $now->modify('-5 minutes'));
        $repository = $this->repository();

        $repository->issue(PasswordResetToken::issue($user->id(), $second->hash(), $now));

        self::assertNotNull($this->storedConsumedAt($first));
        self::assertNull($this->storedConsumedAt($second));
        self::assertNull($repository->resetPassword($first->hash(), self::NEW_HASH, $now->modify('+1 minute')));
        self::assertSame($user->email(), $repository->resetPassword($second->hash(), self::NEW_HASH, $now->modify('+1 minute')));
    }

    public function testResetRevokesOtherLiveLinksOfSameUser(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-10-15 12:00:00');
        $used = PasswordResetSecret::generate();
        $other = PasswordResetSecret::generate();
        $user = $this->userWithToken($used, $now->modify('-5 minutes'));
        // Живая соседняя ссылка возможна только в обход issue(), но
        // гарантию «после сброса ни одна ссылка не действует» держит
        // само погашение, а не порядок выпуска.
        PasswordResetTokenBuilder::aToken($user, $other->hash())
            ->withIssuedAt($now->modify('-4 minutes'))
            ->persistWith($this->entityManager());

        self::assertNotNull($this->repository()->resetPassword($used->hash(), self::NEW_HASH, $now));

        self::assertNotNull($this->storedConsumedAt($other));
    }

    public function testResetWaitsForTheSameUserLockAsIssue(): void
    {
        self::bootKernel();
        $secret = PasswordResetSecret::generate();
        $user = $this->userWithToken($secret, new \DateTimeImmutable('-1 minute'));
        // Выпуск новой ссылки держит блокировку пользователя: погашение
        // обязано её дождаться, иначе новая ссылка пережила бы сброс.
        $issuer = $this->independentConnection();
        $issuer->beginTransaction();
        $issuer->executeStatement(
            "SELECT pg_advisory_xact_lock(hashtextextended('conwix.identity.password-reset:' || :user_id, 0))",
            ['user_id' => $user->id()->toRfc4122()],
        );

        try {
            $this->connection()->executeStatement("SET LOCAL lock_timeout = '100ms'");
            $this->expectException(DriverException::class);

            $this->repository()->resetPassword($secret->hash(), self::NEW_HASH, new \DateTimeImmutable());
        } finally {
            $issuer->rollBack();
            $issuer->close();
        }
    }

    public function testOnlyHashIsStored(): void
    {
        self::bootKernel();
        $secret = PasswordResetSecret::generate();
        $this->userWithToken($secret, new \DateTimeImmutable());

        $stored = $this->connection()->fetchOne(
            'SELECT count(*) FROM password_reset_token WHERE token_hash = :plain',
            ['plain' => $secret->plainText()],
        );

        self::assertSame(0, $stored);
    }

    private function userWithToken(PasswordResetSecret $secret, \DateTimeImmutable $issuedAt): User
    {
        $user = UserBuilder::aUser()
            ->withEmail(\sprintf('reset-%s@example.test', Uuid::v7()->toRfc4122()))
            ->withPasswordHash('original-hash')
            ->persistWith(new DoctrineUserRepository($this->entityManager()));
        PasswordResetTokenBuilder::aToken($user, $secret->hash())
            ->withIssuedAt($issuedAt)
            ->persistWith($this->entityManager());

        return $user;
    }

    private function storedPasswordHash(User $user): mixed
    {
        return $this->connection()->fetchOne(
            'SELECT password_hash FROM "user" WHERE id = :id',
            ['id' => $user->id()->toRfc4122()],
        );
    }

    private function storedConsumedAt(PasswordResetSecret $secret): mixed
    {
        return $this->connection()->fetchOne(
            'SELECT consumed_at FROM password_reset_token WHERE token_hash = :hash',
            ['hash' => $secret->hash()],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(User $user): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT company_id, subject_id, previous_value, new_value FROM audit_record WHERE actor_user_id = :user AND action = :action',
            ['user' => $user->id()->toRfc4122(), 'action' => AuditAction::UserPasswordReset],
        );
    }

    private function repository(): DoctrinePasswordResetTokenRepository
    {
        return new DoctrinePasswordResetTokenRepository($this->entityManager());
    }

    private function independentConnection(): Connection
    {
        $params = $this->connection()->getParams();

        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $params['host'],
            'port' => $params['port'],
            'user' => $params['user'],
            'password' => $params['password'],
            'dbname' => $params['dbname'],
            'serverVersion' => $params['serverVersion'],
        ]);
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
