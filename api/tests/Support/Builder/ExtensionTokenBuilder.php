<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\ExtensionToken;
use App\Identity\Domain\ExtensionTokenRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\ValueObject\ExtensionTokenSecret;

/**
 * ADR-005: валидные умолчания, неизменяем, связанные Company/User создаёт
 * через их билдеры, если не заданы явно.
 *
 * Секрет задаётся снаружи через withSecret(): тесту нужен открытый текст,
 * чтобы предъявить его в заголовке, а из записи он не восстанавливается.
 * issuedAt тоже параметр — срок истечения проверяет тест, и билдер
 * не должен вычислять то, что проверяется (ADR-005).
 *
 * Членство в компании билдер не создаёт: это дело CompanyMemberBuilder.
 * Токен без членства — валидное состояние базы, и на нём как раз
 * проверяется, что обработчик перепроверяет членство.
 */
final class ExtensionTokenBuilder
{
    private ?Company $company = null;
    private ?User $user = null;
    private ?ExtensionTokenSecret $secret = null;
    private ?\DateTimeImmutable $issuedAt = null;

    private function __construct()
    {
    }

    public static function anExtensionToken(): self
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

    public function withSecret(ExtensionTokenSecret $secret): self
    {
        $clone = clone $this;
        $clone->secret = $secret;

        return $clone;
    }

    public function withIssuedAt(\DateTimeImmutable $issuedAt): self
    {
        $clone = clone $this;
        $clone->issuedAt = $issuedAt;

        return $clone;
    }

    public function build(): ExtensionToken
    {
        $company = $this->company ?? CompanyBuilder::aCompany()->build();
        $user = $this->user ?? UserBuilder::aUser()->build();

        return $this->issueFor($company, $user);
    }

    public function persistWith(
        CompanyRepository $companies,
        UserRepository $users,
        ExtensionTokenRepository $tokens,
    ): ExtensionToken {
        $company = $this->company ?? CompanyBuilder::aCompany()->persistWith($companies);
        $user = $this->user ?? UserBuilder::aUser()->persistWith($users);

        $token = $this->issueFor($company, $user);
        $tokens->add($token);

        return $token;
    }

    private function issueFor(Company $company, User $user): ExtensionToken
    {
        return ExtensionToken::issue(
            $company->id(),
            $user->id(),
            $this->secret ?? ExtensionTokenSecret::generate(),
            $this->issuedAt ?? new \DateTimeImmutable(),
        );
    }
}
