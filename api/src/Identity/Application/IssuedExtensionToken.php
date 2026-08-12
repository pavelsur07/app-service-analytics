<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ExtensionToken;

/**
 * Результат выпуска: сама запись плюс открытый текст секрета, которого
 * в записи нет и больше нигде не будет (ADR-010). Возвращаются вместе,
 * потому что порознь бесполезны — вызывающий обязан отдать секрет
 * клиенту в этом же ответе.
 */
final readonly class IssuedExtensionToken
{
    public function __construct(
        public ExtensionToken $token,
        public string $plaintext,
    ) {
    }
}
