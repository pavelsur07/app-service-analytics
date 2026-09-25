<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\AuditRecordRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ReplaceCredentialsOutcome;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Domain\ValueObject\MarketplaceCredentials;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

/**
 * Клиент ввёл или заменил рекламный ключ подключения (ADR-026, п. 1).
 *
 * Устроено как ReplaceMarketplaceCredentialsAction, и по тем же причинам:
 * ключ приходит уже подтверждённым площадкой (Identity в площадку
 * не ходит), версия обязательна (ADR-008), аудит-запись ставится в ту же
 * единицу работы, что и изменение (ADR-007: добавление и изменение
 * учётных данных подключений).
 *
 * Отличие одно: объект учётных данных не заменяется, а дополняется.
 * В нём лежит ключ Seller API, и рекламный ключ не имеет права его
 * стереть — подключение осталось бы без продаж ради рекламы.
 */
final readonly class ReplaceAdvertisingCredentialsAction
{
    public const string PerformanceClientIdKey = 'performance_client_id';

    public const string PerformanceClientSecretKey = 'performance_client_secret';

    public function __construct(
        private MarketplaceAccountRepository $marketplaceAccounts,
        private MarketplaceCredentialsEncryptor $credentialsEncryptor,
        private AuditRecordRepository $auditRecords,
    ) {
    }

    public function __invoke(
        string $companyId,
        Uuid $marketplaceAccountId,
        string $performanceClientId,
        string $performanceClientSecret,
        int $expectedVersion,
        Uuid $actorUserId,
    ): ReplaceCredentialsOutcome {
        $account = $this->marketplaceAccounts->get($companyId, $marketplaceAccountId);
        if (null === $account) {
            return ReplaceCredentialsOutcome::NotFound;
        }

        if (MarketplaceAccountState::Revoked === $account->state()) {
            return ReplaceCredentialsOutcome::Revoked;
        }

        if ($account->version() !== $expectedVersion) {
            return ReplaceCredentialsOutcome::VersionConflict;
        }

        $current = $this->credentialsEncryptor->decrypt(
            $account->credentialsCiphertext(),
            $account->credentialsKeyVersion(),
        )->toArray();
        $previousClientId = $current[self::PerformanceClientIdKey] ?? null;
        $previousCiphertext = $account->credentialsCiphertext();

        $encrypted = $this->credentialsEncryptor->encrypt(MarketplaceCredentials::fromArray([
            ...$current,
            self::PerformanceClientIdKey => $performanceClientId,
            self::PerformanceClientSecretKey => $performanceClientSecret,
        ]));
        $account->connectAdvertising($encrypted->ciphertext, $encrypted->keyVersion);

        // «Было» и «стало» — идентификатор рекламного ключа и отпечаток
        // шифротекста, как у замены ключа Seller API, а не секрет и не его
        // хэш (ADR-011): журнал отвечает на вопрос «что изменилось»,
        // и этого требованию достаточно.
        $this->auditRecords->addToUnitOfWork(AuditRecord::record(
            companyId: Uuid::fromString($companyId),
            actorUserId: $actorUserId,
            action: AuditAction::MarketplaceAdvertisingCredentialsReplaced,
            subjectId: $marketplaceAccountId,
            previousValue: null === $previousClientId
                ? null
                : $this->describe($previousClientId, $previousCiphertext),
            newValue: $this->describe($performanceClientId, $encrypted->ciphertext),
            occurredAt: new \DateTimeImmutable(),
        ));

        try {
            $this->marketplaceAccounts->add($account);
        } catch (OptimisticLockException) {
            return ReplaceCredentialsOutcome::VersionConflict;
        }

        return ReplaceCredentialsOutcome::Replaced;
    }

    private function describe(string $clientId, string $ciphertext): string
    {
        return \sprintf('%s (sha256:%s)', $clientId, substr(hash('sha256', $ciphertext), 0, 16));
    }
}
