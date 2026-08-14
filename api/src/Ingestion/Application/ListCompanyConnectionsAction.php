<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\Ingestion\Infrastructure\Query\AccountFreshnessQuery;

/**
 * Экран подключений: состояние подключения плюс свежесть его выгрузок.
 *
 * Сборка живёт в Ingestion, хотя сам список подключений принадлежит
 * Identity, — и это не случайность. Зависимости строго вниз: Ingestion
 * читает Identity через Facade, обратное запрещено, а свежесть лежит
 * в raw-слое Ingestion. Экран, показывающий и то и другое, обязан
 * обслуживаться отсюда.
 *
 * В Application, а не прямо в контроллере: Ui не пересекает границу
 * модуля даже через Facade (deptrac.php).
 */
final readonly class ListCompanyConnectionsAction
{
    public function __construct(
        private IdentityFacade $identityFacade,
        private AccountFreshnessQuery $freshness,
    ) {
    }

    /**
     * @return list<CompanyConnectionView>
     */
    public function __invoke(string $companyId): array
    {
        $connections = $this->identityFacade->listConnections($companyId);
        if ([] === $connections) {
            return [];
        }

        $freshness = $this->freshnessByAccount($companyId);

        return array_map(
            static fn ($connection): CompanyConnectionView => new CompanyConnectionView(
                id: $connection->id,
                marketplace: $connection->marketplace,
                externalShopId: $connection->externalShopId,
                state: $connection->state,
                createdAt: $connection->createdAt,
                lastLoadedAt: $freshness[$connection->id] ?? [],
            ),
            $connections,
        );
    }

    /**
     * Один запрос на все подключения компании, не по запросу на строку
     * (CLAUDE.md §6).
     *
     * @return array<string, array<string, string>>
     */
    private function freshnessByAccount(string $companyId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->freshness->build($companyId)->executeQuery()->fetchAllAssociative();

        $byAccount = [];
        foreach ($rows as $row) {
            $freshness = AccountFreshnessQuery::mapRow($row);
            $byAccount[$freshness->marketplaceAccountId][$freshness->reportType] = $freshness->lastReceivedAt;
        }

        return $byAccount;
    }
}
