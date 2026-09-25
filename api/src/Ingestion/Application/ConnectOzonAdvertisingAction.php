<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\CredentialsReplacementOutcome;
use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Domain\OzonAdCampaign;
use App\Ingestion\Domain\OzonAdCampaignListParser;
use App\Ingestion\Domain\OzonAdCampaignProductsParser;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Domain\OzonAuthorizationFailure;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkuMatchQuery;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Ввод или замена рекламного ключа Performance API (ADR-026, п. 1).
 *
 * Живёт в Ingestion по той же причине, что ReplaceOzonCredentialsAction:
 * проба требует клиента площадки и каталога подключения, а оба — здесь.
 * Сохраняет ключ Identity, через IdentityFacade: зависимость вниз.
 *
 * Проба до сохранения, как у ключа Seller API: непроверенный ключ завёл
 * бы рекламу, которая сломается на первой загрузке, а клиент считал бы,
 * что всё настроил.
 *
 * 1. Токен — ключ действителен.
 * 2. Список кампаний — у ключа есть право читать рекламный кабинет.
 * 3. Товары нескольких последних неархивных кампаний оплаты за клик
 *    сверяются с каталогом подключения. Performance API не отдаёт
 *    Client-Id, и это единственный способ заметить ключ другого кабинета.
 *    Нашлись SKU, каталог не пуст, и ни одного совпадения — ключ чужой.
 *    SKU нет или каталог пуст — проверять не с чем, ключ принимается.
 *
 * Отказы классифицируются тем же приёмом, что у проб ключа Seller API:
 * 401/403 — ключ отклонён, прочие отказы HTTP-клиента — площадка
 * не ответила, всё остальное — наш дефект, пробрасывается.
 */
final readonly class ConnectOzonAdvertisingAction
{
    /**
     * Сколько кампаний пробуется на товары. Больше не нужно: чужой кабинет
     * виден по первой же кампании с товарами, а каждая — ещё один запрос.
     */
    private const int PROBED_CAMPAIGNS = 3;

    public function __construct(
        private OzonAdvertisingFetcher $fetcher,
        private OzonAdCampaignListParser $campaignParser,
        private OzonAdCampaignProductsParser $productsParser,
        private AccountCatalogSkuMatchQuery $catalogMatch,
        private IdentityFacade $identityFacade,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        string $companyId,
        string $marketplaceAccountId,
        string $performanceClientId,
        string $performanceClientSecret,
        int $expectedVersion,
        string $actorUserId,
    ): ConnectAdvertisingResult {
        if (!$this->connectionExists($companyId, $marketplaceAccountId)) {
            return ConnectAdvertisingResult::NotFound;
        }

        try {
            $token = $this->fetcher->token($performanceClientId, $performanceClientSecret);
            $campaigns = $this->campaignParser->parse($this->fetcher->campaigns($token));
            $skus = $this->probedSkus($token, $campaigns);
        } catch (\Throwable $failure) {
            return $this->classifyProbeFailure($failure, $performanceClientId);
        }

        if ([] !== $skus && $this->belongsToAnotherCabinet($companyId, $marketplaceAccountId, $skus)) {
            return ConnectAdvertisingResult::WrongCabinet;
        }

        return match ($this->identityFacade->replaceAdvertisingCredentials(
            $companyId,
            $marketplaceAccountId,
            $performanceClientId,
            $performanceClientSecret,
            $expectedVersion,
            $actorUserId,
        )) {
            CredentialsReplacementOutcome::Replaced => ConnectAdvertisingResult::Connected,
            CredentialsReplacementOutcome::NotFound => ConnectAdvertisingResult::NotFound,
            CredentialsReplacementOutcome::Revoked => ConnectAdvertisingResult::Revoked,
            CredentialsReplacementOutcome::VersionConflict => ConnectAdvertisingResult::VersionConflict,
        };
    }

    /**
     * Кампании в архиве отвечают на запрос товаров 400 «товары перенесены
     * в архив» — поэтому пробуются только неархивные, и только оплата
     * за клик: у других типов списка товаров нет.
     *
     * @param list<OzonAdCampaign> $campaigns
     *
     * @return list<string>
     */
    private function probedSkus(string $token, array $campaigns): array
    {
        $candidates = array_values(array_filter(
            $campaigns,
            static fn (OzonAdCampaign $campaign): bool => OzonAdCampaign::StateArchived !== $campaign->state
                && OzonAdCampaign::TypeSku === $campaign->advObjectType,
        ));
        usort($candidates, static fn (OzonAdCampaign $a, OzonAdCampaign $b): int => $b->createdAt <=> $a->createdAt);

        $skus = [];
        foreach (\array_slice($candidates, 0, self::PROBED_CAMPAIGNS) as $campaign) {
            try {
                $body = $this->fetcher->campaignProducts($token, $campaign->id);
            } catch (ClientExceptionInterface $refused) {
                // 400 — кампания отказалась отдать товары (архивные товары
                // у неархивной кампании). Сверке это не мешает: она берёт
                // SKU там, где они есть. Прочие отказы — как у всей пробы.
                if (400 === $refused->getResponse()->getStatusCode()) {
                    continue;
                }

                throw $refused;
            }
            $skus = [...$skus, ...$this->productsParser->skus($body)];
        }

        return array_values(array_unique($skus));
    }

    /**
     * @param list<string> $skus
     */
    private function belongsToAnotherCabinet(string $companyId, string $marketplaceAccountId, array $skus): bool
    {
        $row = $this->catalogMatch->build($companyId, $marketplaceAccountId, $skus)->executeQuery()->fetchAssociative();
        if (false === $row) {
            throw new \UnexpectedValueException('Catalog match query returned no row.');
        }
        $match = AccountCatalogSkuMatchQuery::mapRow($row);

        return $match->hasCatalog && !$match->matched;
    }

    private function classifyProbeFailure(\Throwable $failure, string $performanceClientId): ConnectAdvertisingResult
    {
        if (OzonAuthorizationFailure::isAuthorizationFailure($failure)) {
            return ConnectAdvertisingResult::Rejected;
        }

        if (!$failure instanceof HttpClientExceptionInterface) {
            throw $failure;
        }

        // client_secret в журнал не попадает ни в каком виде; request_id
        // и company_id добавляет RequestContextProcessor.
        $this->logger->warning('Ozon Performance не ответил при проверке рекламного ключа', [
            'performance_client_id' => $performanceClientId,
        ]);

        return ConnectAdvertisingResult::Unavailable;
    }

    private function connectionExists(string $companyId, string $marketplaceAccountId): bool
    {
        foreach ($this->identityFacade->listConnections($companyId) as $connection) {
            if ($connection->id === $marketplaceAccountId) {
                return true;
            }
        }

        return false;
    }
}
