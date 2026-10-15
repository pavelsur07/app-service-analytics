<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\PasswordReset;

final readonly class PasswordResetResponse
{
    public const string PASSWORD_CHANGED = 'password_changed';

    public function __construct(
        public string $status,
    ) {
    }
}
