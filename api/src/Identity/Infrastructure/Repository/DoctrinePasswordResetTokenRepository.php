<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\PasswordResetTokenRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrinePasswordResetTokenRepository implements PasswordResetTokenRepository
{
    private const string REVOKE_ACTIVE_SQL = <<<'SQL'
        UPDATE password_reset_token
        SET consumed_at = :now
        WHERE user_id = :user_id
          AND consumed_at IS NULL
        SQL;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function issue(PasswordResetToken $token): void
    {
        $this->entityManager->getConnection()->transactional(function (Connection $connection) use ($token): void {
            // Два параллельных запроса одного пользователя без сериализации
            // оба погасили бы прежние ссылки до вставки соседа и оставили
            // две живые. Блокировка — на пользователя и только на выпуск:
            // погашение её не берёт и с выпуском не взаимоблокируется.
            $connection->executeStatement(
                "SELECT pg_advisory_xact_lock(hashtextextended('conwix.identity.password-reset-issue:' || :user_id, 0))",
                ['user_id' => $token->userId()->toRfc4122()],
            );
            $connection->executeStatement(self::REVOKE_ACTIVE_SQL, [
                'now' => $token->issuedAt()->format('Y-m-d H:i:s'),
                'user_id' => $token->userId()->toRfc4122(),
            ]);
            $this->entityManager->persist($token);
            $this->entityManager->flush();
        });
    }

    public function resetPassword(string $tokenHash, string $passwordHash, \DateTimeImmutable $now): ?string
    {
        $formattedNow = $now->format('Y-m-d H:i:s');

        return $this->entityManager->getConnection()->transactional(function (Connection $connection) use ($tokenHash, $passwordHash, $now, $formattedNow): ?string {
            // Погашение — условие внутри UPDATE, не проверка перед записью
            // (CLAUDE.md §4): из двух отправок одной ссылки пароль меняет одна.
            $userId = $connection->executeQuery(
                <<<'SQL'
                    UPDATE password_reset_token
                    SET consumed_at = :now
                    WHERE token_hash = :token_hash
                      AND consumed_at IS NULL
                      AND expires_at > :now
                    RETURNING user_id
                    SQL,
                ['now' => $formattedNow, 'token_hash' => $tokenHash],
            )->fetchOne();

            if (false === $userId) {
                return null;
            }
            if (!\is_string($userId)) {
                throw new \UnexpectedValueException('Consumed password reset token must return a string user_id.');
            }

            $email = $connection->executeQuery(
                'UPDATE "user" SET password_hash = :password_hash WHERE id = :user_id RETURNING email',
                ['password_hash' => $passwordHash, 'user_id' => $userId],
            )->fetchOne();
            if (!\is_string($email)) {
                throw new \RuntimeException('Password reset token references a missing user.');
            }

            // После смены пароля ни одна ссылка пользователя не действует.
            $connection->executeStatement(self::REVOKE_ACTIVE_SQL, ['now' => $formattedNow, 'user_id' => $userId]);

            $userUuid = Uuid::fromString($userId);
            $this->entityManager->persist(AuditRecord::recordForUser(
                actorUserId: $userUuid,
                action: AuditAction::UserPasswordReset,
                subjectId: $userUuid,
                previousValue: null,
                newValue: null,
                occurredAt: $now,
            ));
            $this->entityManager->flush();

            return $email;
        });
    }
}
