<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Исход подключения вместе с идентификатором созданной строки: ответ 201
 * обязан назвать созданный ресурс, и читать его вторым запросом сразу
 * после записи незачем.
 */
final readonly class ConnectOzonAccountOutcome
{
    private function __construct(
        public ConnectOzonAccountResult $result,
        /** Заполнен только у Connected. */
        public ?string $accountId,
    ) {
    }

    public static function connected(string $accountId): self
    {
        return new self(ConnectOzonAccountResult::Connected, $accountId);
    }

    public static function failed(ConnectOzonAccountResult $result): self
    {
        return new self($result, null);
    }
}
