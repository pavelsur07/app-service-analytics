<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\CompanyConnection;
use App\Identity\Application\Facade\CredentialsReplacementOutcome;
use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonCatalogFetcher;

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
 */
final readonly class ReplaceOzonCredentialsAction
{
    private const int PROBE_LIMIT = 1;

    public function __construct(
        private OzonCatalogFetcher $client,
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

        try {
            $this->client->fetchPage($clientId, $apiKey, '', self::PROBE_LIMIT);
        } catch (\Throwable $failure) {
            if (OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
                return ReplaceCredentialsResult::Rejected;
            }

            // Остальные отказы — не «ключ неверен»: лимит запросов, сбой
            // площадки, обрыв сети. Сказать клиенту «ключ не подошёл»
            // в этот момент означало бы отправить его выпускать новый
            // вместо того, чтобы подождать.
            throw $failure;
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
