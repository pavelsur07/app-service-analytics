<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\UnconfirmedAccountCleaner;

final readonly class PurgeUnconfirmedAccountsAction
{
    public function __construct(
        private UnconfirmedAccountCleaner $cleaner,
    ) {
    }

    public function __invoke(\DateTimeImmutable $cutoff): int
    {
        return $this->cleaner->purgeCreatedBefore($cutoff);
    }
}
