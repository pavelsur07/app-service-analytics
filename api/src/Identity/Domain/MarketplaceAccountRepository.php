<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\Marketplace;
use Symfony\Component\Uid\Uuid;

interface MarketplaceAccountRepository
{
    public function add(MarketplaceAccount $account): void;

    /**
     * $companyId первым параметром (CLAUDE.md §1): поиск подключения
     * по одному лишь id запрещён, изоляция арендаторов проверяется
     * в каждом методе чтения, а не JOIN'ом или доверием к вызывающему.
     */
    public function get(string $companyId, Uuid $id): ?MarketplaceAccount;

    /**
     * Единственное сознательное исключение из CLAUDE.md §1 в этом
     * интерфейсе: межарендаторное перечисление для планировщика
     * синхронизации, не пользовательский запрос в контексте одной
     * компании. Deptrac не пускает IngestionUi к IdentityFacade вообще —
     * этот метод физически недостижим из HTTP-контроллера.
     *
     * @return list<MarketplaceAccount>
     */
    public function findAllActive(Marketplace $marketplace): array;
}
