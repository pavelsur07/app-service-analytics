<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyMember;
use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\ValueObject\CompanyMemberRole;

/**
 * ADR-005: валидные умолчания, неизменяем, связанные Company/User создаёт
 * через их билдеры, если не заданы явно через withCompany()/withUser().
 */
final class CompanyMemberBuilder
{
    private ?Company $company = null;
    private ?User $user = null;
    private CompanyMemberRole $role = CompanyMemberRole::Owner;

    private function __construct()
    {
    }

    public static function aCompanyMember(): self
    {
        return new self();
    }

    public function withCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->company = $company;

        return $clone;
    }

    public function withUser(User $user): self
    {
        $clone = clone $this;
        $clone->user = $user;

        return $clone;
    }

    public function withRole(CompanyMemberRole $role): self
    {
        $clone = clone $this;
        $clone->role = $role;

        return $clone;
    }

    public function build(): CompanyMember
    {
        $company = $this->company ?? CompanyBuilder::aCompany()->build();
        $user = $this->user ?? UserBuilder::aUser()->build();

        return CompanyMember::create($company->id(), $user->id(), $this->role);
    }

    public function persistWith(CompanyRepository $companies, UserRepository $users, CompanyMemberRepository $companyMembers): CompanyMember
    {
        $company = $this->company ?? CompanyBuilder::aCompany()->persistWith($companies);
        $user = $this->user ?? UserBuilder::aUser()->persistWith($users);

        $member = CompanyMember::create($company->id(), $user->id(), $this->role);
        $companyMembers->add($member);

        return $member;
    }
}
