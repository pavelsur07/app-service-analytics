<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Identity\Application\Facade\IdentityAccountScopeFacade;
use App\Ingestion\Application\Facade\IngestionPlanningFacade;
use App\Planning\Domain\DailyPlanMutationOutcome;

final readonly class DailyPlanAccess
{
    public function __construct(private IdentityAccountScopeFacade $accounts, private IngestionPlanningFacade $ingestion)
    {
    }

    public function check(string $companyId, string $marketplaceAccountId, string $marketplaceSku): DailyPlanMutationOutcome
    {
        if (!$this->accounts->ownsMarketplaceAccount($companyId, $marketplaceAccountId)) {
            return DailyPlanMutationOutcome::AccountNotFound;
        }

        return [$marketplaceSku] === $this->ingestion->knownMarketplaceSkus($companyId, $marketplaceAccountId, [$marketplaceSku])
            ? DailyPlanMutationOutcome::Saved
            : DailyPlanMutationOutcome::UnknownSku;
    }
}
