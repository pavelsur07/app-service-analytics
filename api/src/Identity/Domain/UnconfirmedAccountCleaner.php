<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface UnconfirmedAccountCleaner
{
    public function purgeCreatedBefore(\DateTimeImmutable $cutoff): int;
}
