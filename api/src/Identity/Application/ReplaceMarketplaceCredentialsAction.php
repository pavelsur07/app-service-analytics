<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\AuditRecordRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\MarketplaceCredentials;
use Symfony\Component\Uid\Uuid;

/**
 * Клиент заменил учётные данные подключения (ADR-007).
 *
 * Сюда они приходят уже подтверждёнными площадкой: проверять ключ
 * обязан вызывающий сценарий, иначе клиент сохранил бы неверный ключ,
 * получил broken снова и решил, что сломано у нас. Identity в площадку
 * не ходит и ходить не может — зависимости строго вниз.
 *
 * Аудит-запись обязательна и делается здесь, а не в контроллере:
 * «добавление и изменение учётных данных подключений» — один из четырёх
 * событий, перечисленных в CLAUDE.md. Запись рядом с самим изменением
 * означает, что забыть её нельзя, не заметив.
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
     *
     * @return bool false, если подключения у этой компании нет
     */
    public function __invoke(string $companyId, Uuid $marketplaceAccountId, array $credentials, Uuid $actorUserId): bool
    {
        $account = $this->marketplaceAccounts->get($companyId, $marketplaceAccountId);
        if (null === $account) {
            return false;
        }

        $encrypted = $this->credentialsEncryptor->encrypt(MarketplaceCredentials::fromArray($credentials));
        $account->replaceCredentials($encrypted->ciphertext, $encrypted->keyVersion);
        $this->marketplaceAccounts->add($account);

        $this->auditRecords->add(AuditRecord::record(
            companyId: Uuid::fromString($companyId),
            actorUserId: $actorUserId,
            action: AuditAction::MarketplaceCredentialsReplaced,
            subjectId: $marketplaceAccountId,
            occurredAt: new \DateTimeImmutable(),
        ));

        return true;
    }
}
