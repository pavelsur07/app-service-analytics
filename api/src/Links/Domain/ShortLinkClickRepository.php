<?php

declare(strict_types=1);

namespace App\Links\Domain;

interface ShortLinkClickRepository
{
    public function record(ShortLinkClick $click): void;
}
