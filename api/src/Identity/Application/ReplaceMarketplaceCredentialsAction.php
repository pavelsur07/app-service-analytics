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
 * Клиент заменил учётные данные подключения (ADR-007).
 *
 * Сюда они приходят уже подтверждёнными площадкой: проверять ключ обязан
 * вызывающий сценарий, иначе клиент сохранил бы неверный ключ, получил
 * broken снова и решил, что сломано у нас. Identity в площадку не ходит
 * и ходить не может — зависимости строго вниз.
 *
 * Версия обязательна (ADR-008, уточнение ADR-011): значение правится
 * на месте и вводится человеком, а двое сохранивших подряд молча затрут
 * друг друга. Сверка версии в приложении отвечает клиенту понятным
 * конфликтом; настоящую гонку ловит `#[ORM\Version]` в самом UPDATE.
 *
 * Аудит-запись обязательна и делается здесь, а не в контроллере:
 * «изменение учётных данных подключений» — одно из четырёх событий
 * ADR-007. Она ставится в ту же единицу работы, что и само изменение:
 * иначе возможен исход, при котором ключ заменён, а следа нет.
 */
final readonly class ReplaceMarketplaceCredentialsAction
{
    public function __construct(
        private MarketplaceAccountRepository $marketplaceAccounts,
        private MarketplaceCredentialsEncryptor $credentialsEncryptor,
        private AuditRecordRepository $auditRecords,
    ) {
    }

    /**
     * @param array<string, string> $credentials
     */
    public function __invoke(
        string $companyId,
        Uuid $marketplaceAccountId,
        array $credentials,
        int $expectedVersion,
        Uuid $actorUserId,
    ): ReplaceCredentialsOutcome {
        $account = $this->marketplaceAccounts->get($companyId, $marketplaceAccountId);
        if (null === $account) {
            return ReplaceCredentialsOutcome::NotFound;
        }

        if (MarketplaceAccountState::Revoked === $account->state()) {
            // Отзыв необратим (ADR-011): «оживить» подключение заменой
            // ключа нельзя, и молча оставить его revoked, ответив успехом,
            // — тоже.
            return ReplaceCredentialsOutcome::Revoked;
        }

        if ($account->version() !== $expectedVersion) {
            return ReplaceCredentialsOutcome::VersionConflict;
        }

        $previous = $this->fingerprint($account->credentialsCiphertext());

        $encrypted = $this->credentialsEncryptor->encrypt(MarketplaceCredentials::fromArray($credentials));
        $account->replaceCredentials($encrypted->ciphertext, $encrypted->keyVersion);

        // Запись ставится до сохранения: фиксирует её тот же flush,
        // что и сущность (см. AuditRecordRepository).
        $this->auditRecords->addToUnitOfWork(AuditRecord::record(
            companyId: Uuid::fromString($companyId),
            actorUserId: $actorUserId,
            action: AuditAction::MarketplaceCredentialsReplaced,
            subjectId: $marketplaceAccountId,
            previousValue: $previous,
            newValue: $this->fingerprint($encrypted->ciphertext),
            occurredAt: new \DateTimeImmutable(),
        ));

        try {
            $this->marketplaceAccounts->add($account);
        } catch (OptimisticLockException) {
            // Между чтением и записью подключение изменил кто-то ещё.
            // Версия в самом UPDATE — то, что делает это невозможным
            // тихо, в отличие от сверки в коде выше.
            return ReplaceCredentialsOutcome::VersionConflict;
        }

        return ReplaceCredentialsOutcome::Replaced;
    }

    /**
     * Отпечаток, а не значение: ключ в журнале — тот же секрет, только
     * в таблице без шифрования, чего ADR-007 не допускает. «Было и стало»
     * из ADR-011 отвечает здесь на вопрос «тот же ключ или другой»,
     * и этого требованию достаточно.
     */
    private function fingerprint(string $ciphertext): string
    {
        return 'sha256:'.substr(hash('sha256', $ciphertext), 0, 16);
    }
}
