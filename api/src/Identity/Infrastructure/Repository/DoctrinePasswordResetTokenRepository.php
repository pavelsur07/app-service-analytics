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

    private const string USER_LOCK_SQL = <<<'SQL'
        SELECT pg_advisory_xact_lock(hashtextextended('conwix.identity.password-reset:' || :user_id, 0))
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
            // две живые. Та же блокировка у погашения — см. resetPassword().
            $connection->executeStatement(self::USER_LOCK_SQL, ['user_id' => $token->userId()->toRfc4122()]);
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
            // Пользователь ссылки читается до погашения только чтобы взять
            // его блокировку раньше строк токенов — в том же порядке, что
            // issue(); обратный порядок взаимоблокировался бы с ним. Права
            // это чтение не даёт: решает условие UPDATE ниже.
            //
            // Блокировка выстраивает выпуск и сброс в строгую очередь:
            // ссылка, выпущенная до сброса, им гасится; выпуск, дождавшийся
            // конца сброса, — это новый запрос после смены пароля, и его
            // ссылка законно живёт (ADR-037: гасятся прежние ссылки).
            $lockUserId = $connection->fetchOne(
                'SELECT user_id FROM password_reset_token WHERE token_hash = :token_hash',
                ['token_hash' => $tokenHash],
            );
            if (false === $lockUserId) {
                return null;
            }
            if (!\is_string($lockUserId)) {
                throw new \UnexpectedValueException('Password reset token must reference a string user_id.');
            }
            $connection->executeStatement(self::USER_LOCK_SQL, ['user_id' => $lockUserId]);

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
