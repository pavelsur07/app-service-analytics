<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final readonly class AdminMfaState
{
    public function __construct(public int $version, public bool $enabled)
    {
    }
}
