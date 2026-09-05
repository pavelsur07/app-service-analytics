<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

use App\Identity\Application\MarkMarketplaceAccountBrokenAction;
use App\Identity\Application\ReplaceMarketplaceCredentialsAction;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\AuditRecordRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\ReplaceCredentialsOutcome;
use App\Identity\Domain\ValueObject\Marketplace;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Identity\Domain\ValueObject\MarketplaceCredentials;
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
        private readonly AuditRecordRepository $auditRecords,
    ) {
    }

    /**
     * Замена учётных данных подключения клиентом (ADR-007). Ключ обязан
     * быть проверен площадкой до вызова — Identity в площадку не ходит.
     *
     * Версия обязательна в запросе на изменение (ADR-008): принимать
     * изменение «без версии» как безусловное правило прямо запрещают.
     *
     * @param array<string, string> $credentials
     */
    public function replaceMarketplaceCredentials(
        string $companyId,
        string $marketplaceAccountId,
        array $credentials,
        int $expectedVersion,
        string $actorUserId,
    ): CredentialsReplacementOutcome {
        return match (($this->replaceCredentials)(
            $companyId,
            Uuid::fromString($marketplaceAccountId),
            $credentials,
            $expectedVersion,
            Uuid::fromString($actorUserId),
        )) {
            ReplaceCredentialsOutcome::Replaced => CredentialsReplacementOutcome::Replaced,
            ReplaceCredentialsOutcome::NotFound => CredentialsReplacementOutcome::NotFound,
            ReplaceCredentialsOutcome::Revoked => CredentialsReplacementOutcome::Revoked,
            ReplaceCredentialsOutcome::VersionConflict => CredentialsReplacementOutcome::VersionConflict,
        };
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
                    version: $connection->version,
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

    /**
     * Ставит запись аудит-журнала в единицу работы — фиксирует её тот же
     * flush, что и сущность, которую она описывает (AuditRecordRepository).
     * Иначе журнал и изменение расходились бы при отказе на полпути,
     * а журнал, в котором нет части изменений, хуже отсутствующего:
     * ему верят.
     *
     * Действие — строка, и её смысл принадлежит вызывающему модулю.
     * Identity здесь только хранит журнал и не знает, что такое
     * «себестоимость»: зависимости строго вниз, и обратное знание
     * сделало бы Identity зависимым от Ingestion.
     *
     * Метод общий, а не «записать изменение себестоимости», по той же
     * причине. Узкий он только в одном: запись создаётся здесь, и снаружи
     * нельзя ни изменить, ни удалить уже записанное — журнал, который
     * можно поправить, журналом не является.
     */
    public function recordAuditEntry(
        string $companyId,
        string $actorUserId,
        string $action,
        string $subjectId,
        ?string $previousValue,
        ?string $newValue,
    ): void {
        $this->auditRecords->addToUnitOfWork(AuditRecord::record(
            companyId: Uuid::fromString($companyId),
            actorUserId: Uuid::fromString($actorUserId),
            action: $action,
            subjectId: Uuid::fromString($subjectId),
            previousValue: $previousValue,
            newValue: $newValue,
            occurredAt: new \DateTimeImmutable(),
        ));
    }

    /**
     * Подключение кабинета при онбординге (ADR-021). Ключ обязан быть
     * проверен площадкой до вызова: Identity в площадку не ходит,
     * зависимости строго вниз, и проба живёт в Ingestion.
     *
     * Client-Id становится external_shop_id — под ним подключение заведено,
     * и по нему же работает глобальная уникальность кабинета.
     */
    public function connectOzonAccount(
        string $companyId,
        string $name,
        string $clientId,
        string $apiKey,
        string $actorUserId,
    ): MarketplaceAccountConnection {
        $encrypted = $this->credentialsEncryptor->encrypt(
            MarketplaceCredentials::fromArray(['client_id' => $clientId, 'api_key' => $apiKey]),
        );

        $account = MarketplaceAccount::connect(
            companyId: Uuid::fromString($companyId),
            marketplace: Marketplace::Ozon,
            name: $name,
            externalShopId: $clientId,
            credentialsCiphertext: $encrypted->ciphertext,
            credentialsKeyVersion: $encrypted->keyVersion,
        );

        // «Стало» — название и кабинет, не ключ: журнал не место для секрета
        // (ADR-011). «Было» пусто — подключения до этого не существовало.
        $trail = AuditRecord::record(
            companyId: Uuid::fromString($companyId),
            actorUserId: Uuid::fromString($actorUserId),
            action: AuditAction::MarketplaceAccountConnected,
            subjectId: $account->id(),
            previousValue: null,
            newValue: \sprintf('%s (%s)', $name, $clientId),
            occurredAt: new \DateTimeImmutable(),
        );

        return $this->marketplaceAccounts->tryConnect($account, $trail)
            ? MarketplaceAccountConnection::connected($account->id()->toRfc4122())
            : MarketplaceAccountConnection::alreadyConnected();
    }
}
