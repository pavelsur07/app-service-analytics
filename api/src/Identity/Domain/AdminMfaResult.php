<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final readonly class AdminMfaResult
{
    /** @param list<string> $recoveryCodes */
    public function __construct(public int $version, public array $recoveryCodes = [])
    {
    }
}
