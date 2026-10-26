<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

/** Системный auth lifecycle (ADR-045): у администратора нет companyId. */
interface AdminMfaRepository
{
    public function state(Uuid $administratorId): AdminMfaState;

    public function beginEnrollment(Uuid $administratorId, int $expectedVersion, \DateTimeImmutable $now): AdminMfaEnrollment;

    public function confirmEnrollment(Uuid $administratorId, int $expectedVersion, string $code, \DateTimeImmutable $now): ?AdminMfaResult;

    public function verify(Uuid $administratorId, int $expectedVersion, string $code, \DateTimeImmutable $now): ?AdminMfaResult;

    public function regenerateRecoveryCodes(Uuid $administratorId, int $expectedVersion, string $code, \DateTimeImmutable $now): ?AdminMfaResult;

    public function reset(Uuid $administratorId, Uuid $actorAdminId, string $identityReference, \DateTimeImmutable $now): void;
}
