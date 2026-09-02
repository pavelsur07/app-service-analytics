<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Граница подтверждения адреса до выбора компании (CLAUDE.md §1, ADR-021).
 * Поиск по предъявленному одноразовому хэшу межарендаторный по построению:
 * пользователя и его компанию как раз определяет найденный токен. Открытого
 * списка и поиска по произвольному userId этот контракт не предоставляет.
 */
interface EmailVerificationTokenRepository
{
    public function add(EmailVerificationToken $token): void;

    public function confirm(string $tokenHash, \DateTimeImmutable $now): EmailConfirmationTransition;
}
