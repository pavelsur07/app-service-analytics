<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

use App\Identity\Application\MarkMarketplaceAccountBrokenAction;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use Symfony\Component\Uid\Uuid;

/**
 * Точка входа Ingestion в Identity для company-scoped сценариев —
 * каждый метод требует companyId (CLAUDE.md §1). Межарендаторное
 * чтение для планировщика — намеренно НЕ здесь, а в IdentityScheduleFacade:
 * иначе широкий доступ к этому классу (IngestionApplication целиком)
 * транзитивно открыл бы и его — Deptrac не различает методы одного
 * класса, только классы целиком.
 */
final class IdentityFacade
{
    public function __construct(
        private readonly MarketplaceAccountRepository $marketplaceAccounts,
        private readonly MarketplaceCredentialsEncryptor $credentialsEncryptor,
        private readonly MarkMarketplaceAccountBrokenAction $markAccountBroken,
    ) {
    }

    /**
     * null и на отсутствующее подключение, и на состояние отличное
     * от active (ADR-007: broken/revoked не синхронизируются — «молчаливая
     * остановка синхронизации запрещена» относится к тому, что площадка
     * ничего не подскажет сама, а не к тому, что мы обязаны попытаться
     * снова несмотря на уже известный broken). Перевод в broken при новом
     * отказе авторизации и уведомление клиента — вне tracer bullet
     * (docs/plan/ozon-tracer-bullet.md, пакет 5 «Не входит»); здесь —
     * только не трогать то, что уже помечено сломанным.
     */
    public function findOzonSyncTarget(string $companyId, string $marketplaceAccountId): ?OzonSyncTarget
    {
        $account = $this->marketplaceAccounts->get($companyId, Uuid::fromString($marketplaceAccountId));
        if (null === $account || MarketplaceAccountState::Active !== $account->state()) {
            return null;
        }

        $credentials = $this->credentialsEncryptor->decrypt(
            $account->credentialsCiphertext(),
            $account->credentialsKeyVersion(),
        );

        return new OzonSyncTarget(
            companyId: $account->companyId()->toRfc4122(),
            marketplaceAccountId: $account->id()->toRfc4122(),
            externalShopId: $account->externalShopId(),
            clientId: $credentials->get('client_id'),
            apiKey: $credentials->get('api_key'),
        );
    }

    /**
     * Площадка отказала в авторизации (ADR-007): подключение переводится
     * в broken, клиент получает письмо. Идемпотентно — повторный вызов
     * по уже сломанному подключению не порождает второго письма.
     *
     * Через Facade, а не прямым вызовом Application-сценария: Ingestion
     * не ходит внутрь Identity мимо границы модуля.
     */
    public function markOzonAccountBroken(string $companyId, string $marketplaceAccountId): bool
    {
        return ($this->markAccountBroken)($companyId, $marketplaceAccountId);
    }
}
