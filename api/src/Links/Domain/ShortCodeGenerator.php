<?php

declare(strict_types=1);

namespace App\Links\Domain;

interface ShortCodeGenerator
{
    public function generate(): string;
}
