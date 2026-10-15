<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\PasswordResetSecret;

interface PasswordResetEmailSender
{
    public function sendResetLink(string $email, PasswordResetSecret $secret): void;

    public function sendNoAccountFound(string $email): void;

    /**
     * Уведомление после смены пароля. Отправляется по возможности: пароль
     * к этому моменту уже изменён, и отказ доставки не отменяет сброс.
     */
    public function sendPasswordChanged(string $email): void;
}
