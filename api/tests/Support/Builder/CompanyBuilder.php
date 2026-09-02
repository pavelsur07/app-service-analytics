<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;

/**
 * ADR-005: валидные умолчания, неизменяем, разделяет build() и persistWith().
 */
final class CompanyBuilder
{
    private string $name = 'Test Company';
    private ?\DateTimeImmutable $createdAt = null;

    private function __construct()
    {
    }

    public static function aCompany(): self
    {
        return new self();
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function build(): Company
    {
        return Company::register($this->name, $this->createdAt);
    }

    public function persistWith(CompanyRepository $repository): Company
    {
        $company = $this->build();
        $repository->add($company);

        return $company;
    }
}
