<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

/**
 * Заведённый администратор. Пароля и его хэша здесь нет и быть не может:
 * пароль знает только тот, кто его задал.
 */
final readonly class AdministratorResponse
{
    public function __construct(
        public string $id,
        public string $email,
        public string $role,
        public string $createdAt,
    ) {
    }
}
