<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Узкий межарендаторный lookup только для запроса сброса пароля
 * (CLAUDE.md §1, ADR-037). Адрес не авторизует доступ: найденный User
 * остаётся внутри pre-auth action и используется только для выбора письма.
 */
interface PasswordResetUserByEmailQuery
{
    public function findForPasswordReset(string $email): ?User;
}
