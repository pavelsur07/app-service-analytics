<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\EmailConfirmationTransition;
use App\Identity\Domain\EmailVerificationToken;
use App\Identity\Domain\EmailVerificationTokenRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailConfirmationOutcome;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineEmailVerificationTokenRepository implements EmailVerificationTokenRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(EmailVerificationToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function confirm(string $tokenHash, \DateTimeImmutable $now): EmailConfirmationTransition
    {
        $connection = $this->entityManager->getConnection();
        $formattedNow = $now->format('Y-m-d H:i:s');

        return $connection->transactional(function (Connection $connection) use ($tokenHash, $now, $formattedNow): EmailConfirmationTransition {
            $token = $connection->executeQuery(
                <<<'SQL'
                    UPDATE email_verification_token
                    SET consumed_at = :now
                    WHERE token_hash = :token_hash
                      AND consumed_at IS NULL
                      AND expires_at > :now
                    RETURNING user_id
                    SQL,
                ['now' => $formattedNow, 'token_hash' => $tokenHash],
            )->fetchAssociative();

            if (false === $token) {
                return $this->outcomeForUnchangedToken($connection, $tokenHash);
            }

            $userId = $token['user_id'] ?? null;
            if (!\is_string($userId)) {
                throw new \UnexpectedValueException('Confirmed email token must return a string user_id.');
            }

            $confirmed = $connection->executeQuery(
                <<<'SQL'
                    UPDATE "user"
                    SET email_confirmed_at = :now
                    WHERE id = :user_id
                      AND email_confirmed_at IS NULL
                    RETURNING email
                    SQL,
                ['now' => $formattedNow, 'user_id' => $userId],
            )->fetchAssociative();

            // Другой живой токен того же пользователя мог выиграть гонку.
            // Текущий уже погашен, но второго события подтверждения нет.
            if (false === $confirmed) {
                return new EmailConfirmationTransition(EmailConfirmationOutcome::AlreadyConsumed);
            }

            $companyId = $connection->fetchOne(
                <<<'SQL'
                    SELECT company_id
                    FROM company_member
                    WHERE user_id = :user_id AND role = 'owner'
                    ORDER BY created_at, company_id
                    LIMIT 1
                    SQL,
                ['user_id' => $userId],
            );
            if (!\is_string($companyId)) {
                throw new \RuntimeException('Self-registered owner has no company membership.');
            }

            $userUuid = Uuid::fromString($userId);
            $this->entityManager->persist(AuditRecord::record(
                companyId: Uuid::fromString($companyId),
                actorUserId: $userUuid,
                action: AuditAction::UserEmailConfirmed,
                subjectId: $userUuid,
                previousValue: null,
                newValue: $now->format(\DATE_ATOM),
                occurredAt: $now,
            ));
            $this->entityManager->flush();

            // refresh() переписывает все mapped-поля и несовместим с
            // readonly createdAt. После flush безопасно очищаем identity
            // map и гидрируем свежий principal из уже обновлённой строки.
            $this->entityManager->clear();
            $user = $this->entityManager->find(User::class, $userUuid);
            if (!$user instanceof User) {
                throw new \RuntimeException('Confirmed email token references a missing user.');
            }

            return new EmailConfirmationTransition(EmailConfirmationOutcome::Confirmed, $user);
        });
    }

    private function outcomeForUnchangedToken(Connection $connection, string $tokenHash): EmailConfirmationTransition
    {
        $token = $connection->fetchAssociative(
            'SELECT consumed_at FROM email_verification_token WHERE token_hash = :token_hash',
            ['token_hash' => $tokenHash],
        );

        if (false !== $token && null !== ($token['consumed_at'] ?? null)) {
            return new EmailConfirmationTransition(EmailConfirmationOutcome::AlreadyConsumed);
        }

        // Истёкший и неизвестный токены намеренно неразличимы: endpoint
        // не превращается в oracle существования одноразовых ссылок.
        return new EmailConfirmationTransition(EmailConfirmationOutcome::Expired);
    }
}
