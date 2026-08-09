<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\Marketplace;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use Symfony\Component\Uid\Uuid;

/**
 * Единственная точка входа Ingestion в Identity (deptrac: IngestionApplication
 * и IngestionInfrastructure видят только этот слой, не Identity\Domain
 * напрямую).
 */
final class IdentityFacade
{
    public function __construct(
        private readonly MarketplaceAccountRepository $marketplaceAccounts,
        private readonly MarketplaceCredentialsEncryptor $credentialsEncryptor,
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
     * Межарендаторное перечисление для планировщика синхронизации
     * (app:ingestion:schedule-ozon-sync) — единственное сознательное
     * исключение из CLAUDE.md §1 в этом классе, обоснование то же
     * по форме, что у UserRepository::findByEmail: операционная задача
     * уровня сервиса, не пользовательский запрос в контексте одной
     * компании. Deptrac не пускает IngestionUi к этому классу вообще.
     *
     * @return list<OzonAccountRef>
     */
    public function findActiveOzonSyncTargets(): array
    {
        $accounts = $this->marketplaceAccounts->findAllActive(Marketplace::Ozon);

        return array_map(
            static fn (MarketplaceAccount $account) => new OzonAccountRef(
                companyId: $account->companyId()->toRfc4122(),
                marketplaceAccountId: $account->id()->toRfc4122(),
            ),
            $accounts,
        );
    }
}
