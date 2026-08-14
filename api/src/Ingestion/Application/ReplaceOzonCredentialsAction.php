<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Domain\OzonCatalogFetcher;

/**
 * Замена ключей Ozon клиентом: сначала проверить ключ у площадки,
 * потом сохранить.
 *
 * Порядок именно такой и он важен. Сохранить непроверенный ключ значит
 * вернуть подключение в работу, дать синхронизации упасть на первом же
 * запросе и снова перевести подключение в broken — клиент увидит, что
 * его действие «не сработало», и решит, что сломано у нас.
 *
 * Живёт в Ingestion, хотя меняет данные Identity: проверка требует
 * похода в площадку, а клиент площадки принадлежит Ingestion. Обратное
 * направление запрещено — зависимости строго вниз.
 *
 * Проверочный запрос — первая страница каталога размером в один товар:
 * самый дешёвый ответ, который площадка даёт только на живой ключ.
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
        string $actorUserId,
    ): ReplaceCredentialsResult {
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

        $replaced = $this->identityFacade->replaceMarketplaceCredentials(
            $companyId,
            $marketplaceAccountId,
            ['client_id' => $clientId, 'api_key' => $apiKey],
            $actorUserId,
        );

        return $replaced ? ReplaceCredentialsResult::Replaced : ReplaceCredentialsResult::NotFound;
    }
}
