<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\EmailConfirmationOutcome;

/**
 * Результат атомарного перехода хранилища. Отдельный доменный тип не даёт
 * интерфейсу Domain зависеть от DTO слоя Application.
 */
final readonly class EmailConfirmationTransition
{
    public function __construct(
        public EmailConfirmationOutcome $outcome,
        public ?User $user = null,
    ) {
    }
}
