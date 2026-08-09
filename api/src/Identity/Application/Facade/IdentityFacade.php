<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Query\ActiveOzonAccountRow;
use App\Identity\Infrastructure\Query\ActiveOzonAccountsQuery;
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
        private readonly ActiveOzonAccountsQuery $activeOzonAccounts,
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
     * (app:ingestion:schedule-ozon-sync) — сознательное исключение,
     * оформленное по CLAUDE.md §1 («Исключение — межарендаторное
     * чтение для операционных системных задач»): DBAL-запрос
     * (ActiveOzonAccountsQuery), не метод MarketplaceAccountRepository —
     * интерфейс репозитория без исключений. Deptrac не пускает
     * IngestionUi к этому классу вообще.
     *
     * @return list<OzonAccountRef>
     */
    public function findActiveOzonSyncTargets(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->activeOzonAccounts->build()->executeQuery()->fetchAllAssociative();

        if (\count($rows) > ActiveOzonAccountsQuery::MAX_RESULTS) {
            // Громко, не тихая отдача первых 200 — часть компаний тогда
            // молча перестала бы синхронизироваться (CLAUDE.md §5:
            // список без лимита не отдаётся никогда, но лимит здесь —
            // тревога о реальном масштабе, не тихая обрезка).
            throw new \RuntimeException(\sprintf('Активных Ozon-подключений больше защитного потолка %d — нужна курсорная выборка, не разовый список.', ActiveOzonAccountsQuery::MAX_RESULTS));
        }

        return array_map(
            static fn (array $row): OzonAccountRef => self::toAccountRef(ActiveOzonAccountsQuery::mapRow($row)),
            $rows,
        );
    }

    private static function toAccountRef(ActiveOzonAccountRow $row): OzonAccountRef
    {
        return new OzonAccountRef(
            companyId: $row->companyId,
            marketplaceAccountId: $row->marketplaceAccountId,
        );
    }
}
