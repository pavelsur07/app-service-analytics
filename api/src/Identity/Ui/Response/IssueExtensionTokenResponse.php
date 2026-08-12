<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response;

/**
 * $token — открытый текст секрета, единственный раз за всю жизнь токена:
 * в базе лежит только хэш (ADR-010). Повторный запрос его не вернёт.
 */
final readonly class IssueExtensionTokenResponse
{
    public function __construct(
        public string $id,
        public string $token,
        public string $tokenPrefix,
        public string $expiresAt,
    ) {
    }
}
