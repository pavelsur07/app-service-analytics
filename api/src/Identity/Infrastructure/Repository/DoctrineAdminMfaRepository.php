<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\AdministratorMfa;
use App\Identity\Domain\AdministratorMfaRecoveryCode;
use App\Identity\Domain\AdminMfaEncryption;
use App\Identity\Domain\AdminMfaEnrollment;
use App\Identity\Domain\AdminMfaRepository;
use App\Identity\Domain\AdminMfaResult;
use App\Identity\Domain\AdminMfaState;
use App\Identity\Domain\AdminTotp;
use App\Identity\Domain\AuditRecord;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Системная auth lifecycle-граница ADR-045: секрет читается только для
 * проверки/подтверждения самого администратора. Переходы и одноразовость
 * сериализуются общей транзакционной блокировкой; аудит — в той же транзакции.
 */
final readonly class DoctrineAdminMfaRepository implements AdminMfaRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AdminTotp $totp,
        private AdminMfaEncryption $encryption,
    ) {
    }

    public function state(Uuid $administratorId): AdminMfaState
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT version, enabled_at FROM administrator_mfa WHERE administrator_id = :id',
            ['id' => $administratorId->toRfc4122()],
        );

        return false === $row ? new AdminMfaState(0, false) : new AdminMfaState(self::integer($row['version']), null !== $row['enabled_at']);
    }

    public function beginEnrollment(Uuid $administratorId, int $expectedVersion, \DateTimeImmutable $now): AdminMfaEnrollment
    {
        return $this->locked($administratorId, function (Connection $connection) use ($administratorId, $expectedVersion, $now): AdminMfaEnrollment {
            $row = $this->row($administratorId);
            if ((null === $row ? 0 : $row['version']) !== $expectedVersion || (null !== $row && null !== $row['enabled_at'])) {
                throw new \DomainException('mfa_state_changed');
            }
            if (null !== $row) {
                return new AdminMfaEnrollment($this->encryption->decrypt($row['encrypted_secret']), $row['version']);
            }
            $secret = $this->totp->generateSecret();
            $this->entityManager->persist(AdministratorMfa::enroll($administratorId, $this->encryption->encrypt($secret), $now));
            $this->audit($administratorId, 'administrator.mfa_enrollment_started', $now);
            $this->entityManager->flush();

            return new AdminMfaEnrollment($secret, 1);
        });
    }

    public function confirmEnrollment(Uuid $administratorId, int $expectedVersion, string $code, \DateTimeImmutable $now): ?AdminMfaResult
    {
        return $this->locked($administratorId, function (Connection $connection) use ($administratorId, $expectedVersion, $code, $now): ?AdminMfaResult {
            $row = $this->row($administratorId);
            if (null === $row || $row['version'] !== $expectedVersion || null !== $row['enabled_at']) {
                return null;
            }
            $step = $this->totp->matchTimeStep($this->encryption->decrypt($row['encrypted_secret']), $code, $now->getTimestamp());
            if (null === $step) {
                return null;
            }
            $connection->executeStatement(
                'UPDATE administrator_mfa SET enabled_at = :now, last_used_step = :step WHERE administrator_id = :id AND enabled_at IS NULL AND version = :version',
                ['now' => $now->format('Y-m-d H:i:s'), 'step' => $step, 'id' => $administratorId->toRfc4122(), 'version' => $expectedVersion],
            );
            $codes = $this->issueCodes($administratorId, $now);
            $this->audit($administratorId, 'administrator.mfa_enabled', $now);
            $this->entityManager->flush();

            return new AdminMfaResult($expectedVersion, $codes);
        });
    }

    public function verify(Uuid $administratorId, int $expectedVersion, string $code, \DateTimeImmutable $now): ?AdminMfaResult
    {
        return $this->locked($administratorId, function (Connection $connection) use ($administratorId, $expectedVersion, $code, $now): ?AdminMfaResult {
            if (!$this->consume($administratorId, $expectedVersion, $code, $now)) {
                return null;
            }
            $this->audit($administratorId, 'administrator.mfa_verified', $now);
            $this->entityManager->flush();

            return new AdminMfaResult($expectedVersion);
        });
    }

    public function regenerateRecoveryCodes(Uuid $administratorId, int $expectedVersion, string $code, \DateTimeImmutable $now): ?AdminMfaResult
    {
        return $this->locked($administratorId, function (Connection $connection) use ($administratorId, $expectedVersion, $code, $now): ?AdminMfaResult {
            if (!$this->consume($administratorId, $expectedVersion, $code, $now)) {
                return null;
            }
            $connection->executeStatement('UPDATE administrator_mfa SET version = version + 1 WHERE administrator_id = :id', ['id' => $administratorId->toRfc4122()]);
            $codes = $this->issueCodes($administratorId, $now);
            $this->audit($administratorId, 'administrator.mfa_recovery_codes_replaced', $now);
            $this->entityManager->flush();

            return new AdminMfaResult($expectedVersion + 1, $codes);
        });
    }

    public function reset(Uuid $administratorId, Uuid $actorAdminId, string $identityReference, \DateTimeImmutable $now): void
    {
        if (1 !== preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{2,127}\z/', $identityReference)) {
            throw new \InvalidArgumentException('A non-sensitive identity verification reference is required.');
        }
        $this->locked($administratorId, function (Connection $connection) use ($administratorId, $actorAdminId, $identityReference, $now): void {
            if (null === $this->row($administratorId)) {
                throw new \DomainException('mfa_not_configured');
            }
            $connection->executeStatement(
                'UPDATE administrator_mfa SET encrypted_secret = :secret, enabled_at = NULL, last_used_step = NULL, version = version + 1 WHERE administrator_id = :id',
                ['secret' => $this->encryption->encrypt($this->totp->generateSecret()), 'id' => $administratorId->toRfc4122()],
            );
            $connection->executeStatement(
                'UPDATE administrator_mfa_recovery_code SET consumed_at = :now WHERE administrator_id = :id AND consumed_at IS NULL',
                ['now' => $now->format('Y-m-d H:i:s'), 'id' => $administratorId->toRfc4122()],
            );
            $this->entityManager->persist(AuditRecord::recordByAdmin(null, $actorAdminId, 'administrator.mfa_reset', $administratorId, null, $identityReference, $now));
            $this->entityManager->flush();
        });
    }

    private function consume(Uuid $administratorId, int $expectedVersion, string $code, \DateTimeImmutable $now): bool
    {
        $row = $this->row($administratorId);
        if (null === $row || null === $row['enabled_at'] || $row['version'] !== $expectedVersion) {
            return false;
        }
        if (1 === preg_match('/\A[0-9a-f]{32}\z/', $code)) {
            return $this->connection()->executeStatement(
                'UPDATE administrator_mfa_recovery_code SET consumed_at = :now WHERE administrator_id = :id AND code_hash = :hash AND consumed_at IS NULL',
                ['now' => $now->format('Y-m-d H:i:s'), 'id' => $administratorId->toRfc4122(), 'hash' => hash('sha256', $code)],
            ) > 0;
        }
        $step = $this->totp->matchTimeStep($this->encryption->decrypt($row['encrypted_secret']), $code, $now->getTimestamp());
        if (null === $step) {
            return false;
        }

        return $this->connection()->executeStatement(
            'UPDATE administrator_mfa SET last_used_step = :step WHERE administrator_id = :id AND version = :version AND (last_used_step IS NULL OR last_used_step < :step)',
            ['step' => $step, 'id' => $administratorId->toRfc4122(), 'version' => $expectedVersion],
        ) > 0;
    }

    /** @return list<string> */
    private function issueCodes(Uuid $administratorId, \DateTimeImmutable $now): array
    {
        $this->connection()->executeStatement(
            'UPDATE administrator_mfa_recovery_code SET consumed_at = :now WHERE administrator_id = :id AND consumed_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s'), 'id' => $administratorId->toRfc4122()],
        );
        $codes = [];
        for ($index = 0; $index < 10; ++$index) {
            $code = bin2hex(random_bytes(16));
            $this->entityManager->persist(AdministratorMfaRecoveryCode::issue($administratorId, hash('sha256', $code)));
            $codes[] = $code;
        }

        return $codes;
    }

    /** @return array{encrypted_secret: string, enabled_at: ?string, version: int}|null */
    private function row(Uuid $administratorId): ?array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT encrypted_secret, enabled_at, version FROM administrator_mfa WHERE administrator_id = :id',
            ['id' => $administratorId->toRfc4122()],
        );
        if (false === $row) {
            return null;
        }
        if (!\is_string($row['encrypted_secret']) || (null !== $row['enabled_at'] && !\is_string($row['enabled_at']))) {
            throw new \UnexpectedValueException('Invalid administrator MFA row.');
        }

        return ['encrypted_secret' => $row['encrypted_secret'], 'enabled_at' => $row['enabled_at'], 'version' => self::integer($row['version'])];
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $operation
     *
     * @return T
     */
    private function locked(Uuid $administratorId, \Closure $operation): mixed
    {
        return $this->connection()->transactional(static function (Connection $connection) use ($administratorId, $operation): mixed {
            $connection->executeStatement(
                "SELECT pg_advisory_xact_lock(hashtextextended('conwix.identity.admin-mfa:' || :id, 0))",
                ['id' => $administratorId->toRfc4122()],
            );

            return $operation($connection);
        });
    }

    private function audit(Uuid $administratorId, string $action, \DateTimeImmutable $now, ?string $reference = null): void
    {
        $this->entityManager->persist(AuditRecord::recordByAdmin(null, $administratorId, $action, $administratorId, null, $reference, $now));
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private static function integer(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        throw new \UnexpectedValueException('Invalid MFA version.');
    }
}
