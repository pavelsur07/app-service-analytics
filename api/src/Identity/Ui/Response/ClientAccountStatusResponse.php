<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

/**
 * `changed: false` — аккаунт уже был в этом состоянии. Не ошибка:
 * кнопку нажимают дважды, а переход условный и повтор ничего не меняет
 * (ADR-017). Интерфейсу этого достаточно, чтобы не показывать
 * «выполнено» там, где ничего не произошло.
 */
final readonly class ClientAccountStatusResponse
{
    public function __construct(
        public string $status,
        public bool $changed,
    ) {
    }
}
