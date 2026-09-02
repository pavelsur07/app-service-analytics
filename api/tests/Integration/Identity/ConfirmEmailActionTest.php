<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Application\ConfirmEmailAction;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailConfirmationOutcome;
use App\Identity\Domain\ValueObject\EmailVerificationSecret;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineEmailVerificationTokenRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\EmailVerificationTokenBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class ConfirmEmailActionTest extends KernelTestCase
{
    public function testValidTokenConfirmsUserConsumesTokenAndWritesOneAuditRecord(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-09-02T12:00:00+00:00');
        $secret = EmailVerificationSecret::generate();
        $user = $this->accountWithToken($secret, $now->modify('-1 hour'));

        $result = ($this->action())($secret, $now);

        self::assertSame(EmailConfirmationOutcome::Confirmed, $result->outcome);
        self::assertNotNull($result->user);
        self::assertSame($user->id()->toRfc4122(), $result->user->id()->toRfc4122());
        self::assertSame($now->getTimestamp(), $result->user->emailConfirmedAt()?->getTimestamp());

        $token = $this->connection()->fetchAssociative(
            'SELECT consumed_at FROM email_verification_token WHERE token_hash = :hash',
            ['hash' => $secret->hash()],
        );
        self::assertIsArray($token);
        self::assertIsString($token['consumed_at']);
        self::assertStringStartsWith('2026-09-02 12:00:00', $token['consumed_at']);

        $records = $this->auditRows($user);
        self::assertCount(1, $records);
        self::assertSame($user->id()->toRfc4122(), $records[0]['actor_user_id']);
        self::assertNull($records[0]['actor_admin_id']);
        self::assertSame($user->id()->toRfc4122(), $records[0]['subject_id']);
        self::assertSame($now->format(\DATE_ATOM), $records[0]['new_value']);
    }

    public function testSameTokenOnlyConfirmsAndAuditsOnce(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-09-02T12:00:00+00:00');
        $secret = EmailVerificationSecret::generate();
        $user = $this->accountWithToken($secret, $now->modify('-1 hour'));
        $action = $this->action();

        self::assertSame(EmailConfirmationOutcome::Confirmed, $action($secret, $now)->outcome);
        self::assertSame(EmailConfirmationOutcome::AlreadyConsumed, $action($secret, $now->modify('+1 second'))->outcome);
        self::assertCount(1, $this->auditRows($user));
    }

    public function testExpiredAndUnknownTokensDoNotChangeRowsOrWriteAudit(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-09-02T12:00:00+00:00');
        $expired = EmailVerificationSecret::generate();
        $user = $this->accountWithToken($expired, $now->modify('-24 hours'));
        $action = $this->action();

        self::assertSame(EmailConfirmationOutcome::Expired, $action($expired, $now)->outcome);
        self::assertSame(
            EmailConfirmationOutcome::Expired,
            $action(EmailVerificationSecret::generate(), $now)->outcome,
            'неизвестный секрет не становится token oracle',
        );
        self::assertNull($this->storedEmailConfirmedAt($user));
        self::assertNull($this->storedConsumedAt($expired));
        self::assertCount(0, $this->auditRows($user));
    }

    public function testSecondLiveTokenForSameUserCannotCreateSecondConfirmationEvent(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-09-02T12:00:00+00:00');
        $first = EmailVerificationSecret::generate();
        $second = EmailVerificationSecret::generate();
        $user = $this->accountWithToken($first, $now->modify('-1 hour'));
        EmailVerificationTokenBuilder::aToken($user, $second->hash())
            ->withIssuedAt($now->modify('-30 minutes'))
            ->persistWith($this->entityManager());
        $action = $this->action();

        self::assertSame(EmailConfirmationOutcome::Confirmed, $action($first, $now)->outcome);
        self::assertSame(EmailConfirmationOutcome::AlreadyConsumed, $action($second, $now->modify('+1 second'))->outcome);
        self::assertNotNull($this->storedConsumedAt($first));
        self::assertNotNull($this->storedConsumedAt($second));
        self::assertCount(1, $this->auditRows($user));
    }

    private function accountWithToken(EmailVerificationSecret $secret, \DateTimeImmutable $issuedAt): User
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        $users = new DoctrineUserRepository($this->entityManager());
        $members = new DoctrineCompanyMemberRepository($this->entityManager());
        $company = CompanyBuilder::aCompany()
            ->withName('Confirmation test '.Uuid::v7()->toRfc4122())
            ->persistWith($companies);
        $user = UserBuilder::aUser()
            ->withEmail(\sprintf('confirmation-%s@example.test', Uuid::v7()->toRfc4122()))
            ->unconfirmed()
            ->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($companies, $users, $members);
        EmailVerificationTokenBuilder::aToken($user, $secret->hash())
            ->withIssuedAt($issuedAt)
            ->persistWith($this->entityManager());

        return $user;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(User $user): array
    {
        return $this->connection()->fetchAllAssociative(
            'SELECT actor_user_id, actor_admin_id, subject_id, new_value FROM audit_record WHERE actor_user_id = :user AND action = :action ORDER BY occurred_at',
            ['user' => $user->id()->toRfc4122(), 'action' => AuditAction::UserEmailConfirmed],
        );
    }

    private function storedEmailConfirmedAt(User $user): ?string
    {
        $value = $this->connection()->fetchOne(
            'SELECT email_confirmed_at FROM "user" WHERE id = :id',
            ['id' => $user->id()->toRfc4122()],
        );

        return \is_string($value) ? $value : null;
    }

    private function storedConsumedAt(EmailVerificationSecret $secret): ?string
    {
        $value = $this->connection()->fetchOne(
            'SELECT consumed_at FROM email_verification_token WHERE token_hash = :hash',
            ['hash' => $secret->hash()],
        );

        return \is_string($value) ? $value : null;
    }

    private function action(): ConfirmEmailAction
    {
        return new ConfirmEmailAction(new DoctrineEmailVerificationTokenRepository($this->entityManager()));
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
