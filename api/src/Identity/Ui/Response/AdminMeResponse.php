<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

/**
 * Кто вошёл в системный контур. Один DTO на вход и на /me, в отличие
 * от контура продавца: там MeResponse несёт ещё список компаний,
 * а здесь различать нечего — обоим ответам сказать больше нечего.
 */
final readonly class AdminMeResponse
{
    public function __construct(
        public string $email,
        public string $role,
    ) {
    }
}
