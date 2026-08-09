<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface CompanyRepository
{
    public function add(Company $company): void;
}
