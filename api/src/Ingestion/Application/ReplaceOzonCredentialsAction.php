<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\CompanyConnection;
use App\Identity\Application\Facade\CredentialsReplacementOutcome;
use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Domain\OzonExpensesFetcher;
use App\Ingestion\Domain\OzonPostingsFetcher;
use App\Ingestion\Domain\OzonReturnsFetcher;

/**
 * Замена ключей Ozon клиентом: убедиться, что ключ живой и от этого
 * кабинета, и только потом сохранить.
 *
 * Порядок важен. Сохранить непроверенный ключ значит вернуть подключение
 * в работу, дать синхронизации упасть на первом же запросе и снова
 * перевести подключение в broken — клиент увидит, что его действие
 * «не сработало», и решит, что сломано у нас.
 *
 * Живёт в Ingestion, хотя меняет данные Identity: проверка требует похода
 * в площадку, а клиент площадки принадлежит Ingestion. Обратное
 * направление запрещено — зависимости строго вниз.
 *
 * **Проба покрывает все четыре области синхронизации, а не одну** —
 * тот же приём и то же обоснование, что у ConnectOzonAccountAction:
 * замена ключа, прошедшего только товарную область, оживила бы
 * подключение на несколько секунд и сломала бы его снова на первом же
 * реальном запросе продаж, расходов или возвратов. `/v3/product/info/list`
 * отдельной пробой не идёт по той же причине — см. docblock
 * ConnectOzonAccountAction.
 *
 * Отказы, отличные от 401/403, здесь не превращаются в отдельный исход
 * `Unavailable`: этот контракт эндпоинта уже используется фронтендом,
 * и его расширение — отдельное решение (CLAUDE.md, «Когда остановиться
 * и спросить»). Лимит запросов, сбой площадки, обрыв сети остаются
 * исключениями и пробрасываются, как и раньше.
 */
final readonly class ReplaceOzonCredentialsAction
{
    private const int PROBE_LIMIT = 1;

    public function __construct(
        private OzonCatalogFetcher $catalogFetcher,
        private OzonPostingsFetcher $postingsFetcher,
        private OzonExpensesFetcher $expensesFetcher,
        private OzonReturnsFetcher $returnsFetcher,
        private IdentityFacade $identityFacade,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $marketplaceAccountId,
        string $clientId,
        string $apiKey,
        int $expectedVersion,
        string $actorUserId,
    ): ReplaceCredentialsResult {
        $connection = $this->connectionOf($companyId, $marketplaceAccountId);
        if (null === $connection) {
            return ReplaceCredentialsResult::NotFound;
        }

        // Client-Id и есть идентификатор кабинета, под которым заведено
        // подключение (external_shop_id). Ключ от другого кабинета живой
        // и проверку у площадки прошёл бы — а данные чужого магазина
        // записались бы под это подключение и молча испортили аналитику.
        if ($clientId !== $connection->externalShopId) {
            return ReplaceCredentialsResult::WrongCabinet;
        }

        $rejected = $this->probeAllScopes($clientId, $apiKey);
        if (null !== $rejected) {
            return $rejected;
        }

        return match ($this->identityFacade->replaceMarketplaceCredentials(
            $companyId,
            $marketplaceAccountId,
            ['client_id' => $clientId, 'api_key' => $apiKey],
            $expectedVersion,
            $actorUserId,
        )) {
            CredentialsReplacementOutcome::Replaced => ReplaceCredentialsResult::Replaced,
            CredentialsReplacementOutcome::NotFound => ReplaceCredentialsResult::NotFound,
            CredentialsReplacementOutcome::Revoked => ReplaceCredentialsResult::Revoked,
            CredentialsReplacementOutcome::VersionConflict => ReplaceCredentialsResult::VersionConflict,
        };
    }

    /**
     * Последовательные пробы до первого отказа, тот же порядок и те же
     * минимальные окна, что у ConnectOzonAccountAction. `null` означает,
     * что все четыре области подтверждены.
     */
    private function probeAllScopes(string $clientId, string $apiKey): ?ReplaceCredentialsResult
    {
        $now = new \DateTimeImmutable();
        $probeSince = $now->modify('-1 minute');

        try {
            $this->catalogFetcher->fetchPage($clientId, $apiKey, '', self::PROBE_LIMIT);
        } catch (\Throwable $failure) {
            return $this->rejectedOrRethrow($failure, ReplaceCredentialsResult::Rejected);
        }

        try {
            $this->postingsFetcher->fetch($clientId, $apiKey, $probeSince, $now);
        } catch (\Throwable $failure) {
            return $this->rejectedOrRethrow($failure, ReplaceCredentialsResult::RejectedSales);
        }

        try {
            $this->expensesFetcher->fetchDay($clientId, $apiKey, $now, '');
        } catch (\Throwable $failure) {
            return $this->rejectedOrRethrow($failure, ReplaceCredentialsResult::RejectedExpenses);
        }

        try {
            $this->returnsFetcher->fetchPage($clientId, $apiKey, $probeSince, $now, 0, self::PROBE_LIMIT);
        } catch (\Throwable $failure) {
            return $this->rejectedOrRethrow($failure, ReplaceCredentialsResult::RejectedReturns);
        }

        return null;
    }

    private function rejectedOrRethrow(\Throwable $failure, ReplaceCredentialsResult $rejected): ReplaceCredentialsResult
    {
        if (OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
            return $rejected;
        }

        // Остальные отказы — не «ключ неверен»: лимит запросов, сбой
        // площадки, обрыв сети. Сказать клиенту «ключ не подошёл»
        // в этот момент означало бы отправить его выпускать новый
        // вместо того, чтобы подождать.
        throw $failure;
    }

    private function connectionOf(string $companyId, string $marketplaceAccountId): ?CompanyConnection
    {
        foreach ($this->identityFacade->listConnections($companyId) as $connection) {
            if ($connection->id === $marketplaceAccountId) {
                return $connection;
            }
        }

        return null;
    }
}
