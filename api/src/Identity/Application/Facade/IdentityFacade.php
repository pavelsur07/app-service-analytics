<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

use App\Identity\Application\MarkMarketplaceAccountBrokenAction;
use App\Identity\Application\ReplaceMarketplaceCredentialsAction;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Infrastructure\Query\CompanyConnectionsQuery;
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
        private readonly CompanyConnectionsQuery $connections,
        private readonly ReplaceMarketplaceCredentialsAction $replaceCredentials,
    ) {
    }

    /**
     * Замена учётных данных подключения клиентом (ADR-007). Ключ обязан
     * быть проверен площадкой до вызова — Identity в площадку не ходит.
     *
     * @param array<string, string> $credentials
     *
     * @return bool false, если подключения у этой компании нет
     */
    public function replaceMarketplaceCredentials(string $companyId, string $marketplaceAccountId, array $credentials, string $actorUserId): bool
    {
        return ($this->replaceCredentials)(
            $companyId,
            Uuid::fromString($marketplaceAccountId),
            $credentials,
            Uuid::fromString($actorUserId),
        );
    }

    /**
     * Подключения компании для экрана — состояние и происхождение,
     * без учётных данных (их не выбирает и сам запрос).
     *
     * Свежесть данных сюда не входит и войти не может: она живёт
     * в raw-слое Ingestion, а Identity в Ingestion не ходит — зависимости
     * строго вниз. Склеивает их вызывающая сторона, в Ingestion.
     *
     * @return list<CompanyConnection>
     */
    public function listConnections(string $companyId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connections->build($companyId)->executeQuery()->fetchAllAssociative();

        if (\count($rows) > CompanyConnectionsQuery::MAX_RESULTS) {
            // Громко, не тихая обрезка: список подключений без части строк
            // выглядит как полный, и клиент решит, что магазин отключён.
            // Тот же приём, что в IdentityScheduleFacade.
            throw new \RuntimeException(\sprintf('Подключений у компании больше защитного потолка %d — экрану нужна пагинация.', CompanyConnectionsQuery::MAX_RESULTS));
        }

        return array_map(
            static function (array $row): CompanyConnection {
                $connection = CompanyConnectionsQuery::mapRow($row);

                return new CompanyConnection(
                    id: $connection->id,
                    marketplace: $connection->marketplace,
                    externalShopId: $connection->externalShopId,
                    state: $connection->state,
                    createdAt: $connection->createdAt,
                );
            },
            $rows,
        );
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
