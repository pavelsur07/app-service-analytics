<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdministratorMfaRecoveryCode;
use Doctrine\ORM\EntityManagerInterface;

/** Хэш задаётся явно: открытый резервный код не хранится в сущности. */
final readonly class AdministratorMfaRecoveryCodeBuilder
{
    private function __construct(
        private Administrator $administrator,
        private string $codeHash,
    ) {
    }

    public static function aRecoveryCode(Administrator $administrator, string $codeHash): self
    {
        return new self($administrator, $codeHash);
    }

    public function build(): AdministratorMfaRecoveryCode
    {
        return AdministratorMfaRecoveryCode::issue($this->administrator->id(), $this->codeHash);
    }

    public function persistWith(EntityManagerInterface $entityManager): AdministratorMfaRecoveryCode
    {
        $code = $this->build();
        $entityManager->persist($code);
        $entityManager->flush();

        return $code;
    }
}
