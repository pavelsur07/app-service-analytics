<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Узкий межарендаторный lookup только для resend (CLAUDE.md §1, ADR-021).
 * Адрес не авторизует доступ: найденный User остаётся внутри pre-auth
 * lifecycle action и используется только для выбора нейтрального письма.
 */
interface EmailVerificationUserByEmailQuery
{
    public function findForResend(string $email): ?User;
}
