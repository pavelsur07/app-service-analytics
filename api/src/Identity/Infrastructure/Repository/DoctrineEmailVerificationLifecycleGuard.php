<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\EmailVerificationLifecycleGuard;
use Doctrine\DBAL\Connection;

/** PostgreSQL session-lock: живёт дольше отдельных flush внутри operation. */
final readonly class DoctrineEmailVerificationLifecycleGuard implements EmailVerificationLifecycleGuard
{
    private const string LOCK_SQL = <<<'SQL'
        SELECT pg_advisory_lock_shared(
            hashtextextended('conwix.identity.email-verification-maintenance', 0)
        )
        SQL;

    private const string UNLOCK_SQL = <<<'SQL'
        SELECT pg_advisory_unlock_shared(
            hashtextextended('conwix.identity.email-verification-maintenance', 0)
        )
        SQL;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function runShared(\Closure $operation): void
    {
        $this->connection->executeStatement(self::LOCK_SQL);

        try {
            $operation();
        } finally {
            // Session-level lock обязана сниматься и при отказе SMTP.
            // При аварийном завершении процесса её снимет PostgreSQL
            // вместе с соединением.
            $this->connection->executeStatement(self::UNLOCK_SQL);
        }
    }
}
