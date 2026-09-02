<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Tests\Support\Builder\EmailVerificationTokenBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class EmailVerificationSchemaTest extends KernelTestCase
{
    public function testTrustedRegistrationIsConfirmedImmediatelyWithoutLegalConsent(): void
    {
        $user = UserBuilder::aUser()->build();

        self::assertSame($user->createdAt(), $user->emailConfirmedAt());
        self::assertNull($user->legalConsentAt());
        self::assertNull($user->legalDocumentsVersion());
    }

    public function testSelfRegisteredUserAndTokenKeepConfirmationState(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $consentedAt = new \DateTimeImmutable('2026-09-02T10:00:00+00:00');
        $issuedAt = new \DateTimeImmutable('2026-09-02T10:01:00+00:00');
        $user = UserBuilder::aUser()
            ->withEmail(\sprintf('signup-%s@example.com', Uuid::v7()->toRfc4122()))
            ->unconfirmed($consentedAt, '2026-09-02')
            ->build();

        $entityManager->persist($user);
        $token = EmailVerificationTokenBuilder::aToken(
            $user,
            hash('sha256', Uuid::v7()->toRfc4122()),
        )->withIssuedAt($issuedAt)->persistWith($entityManager);

        self::assertNull($user->emailConfirmedAt());
        self::assertSame('2026-09-02', $user->legalDocumentsVersion());
        self::assertSame($consentedAt, $user->legalConsentAt());
        self::assertSame($issuedAt->getTimestamp() + 86_400, $token->expiresAt()->getTimestamp());
        self::assertNull($token->consumedAt());
    }

    public function testTokenHashIsUniqueInDatabase(): void
    {
        self::bootKernel();
        $entityManager = $this->entityManager();
        $user = UserBuilder::aUser()
            ->withEmail(\sprintf('token-%s@example.com', Uuid::v7()->toRfc4122()))
            ->build();

        $entityManager->persist($user);
        $entityManager->flush();

        $connection = $entityManager->getConnection();
        $tokenHash = hash('sha256', Uuid::v7()->toRfc4122());
        $values = [
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $user->id()->toRfc4122(),
            'token_hash' => $tokenHash,
            'issued_at' => '2026-09-02 10:01:00',
            'expires_at' => '2026-09-03 10:01:00',
            'consumed_at' => null,
        ];

        $connection->insert('email_verification_token', $values);

        $this->expectException(UniqueConstraintViolationException::class);
        $values['id'] = Uuid::v7()->toRfc4122();
        $connection->insert('email_verification_token', $values);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
