<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface MarketplaceAccountRepository
{
    public function add(MarketplaceAccount $account): void;

    /**
     * $companyId первым параметром (CLAUDE.md §1): поиск подключения
     * по одному лишь id запрещён, изоляция арендаторов проверяется
     * в каждом методе чтения, а не JOIN'ом или доверием к вызывающему.
     * Без исключений — межарендаторное перечисление для планировщика
     * живёт вне этого интерфейса, в ActiveOzonAccountsQuery (DBAL,
     * тот же приём, что у UserCompaniesQuery).
     */
    public function get(string $companyId, Uuid $id): ?MarketplaceAccount;
}
