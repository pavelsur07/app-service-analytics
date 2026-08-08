<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface MarketplaceAccountRepository
{
    public function add(MarketplaceAccount $account): void;
}
