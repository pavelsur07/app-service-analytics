<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\Connections;

/**
 * Ответ на принятый рекламный ключ. Самого ключа в нём нет — по той же
 * причине, что у ReplacedCredentialsResponse.
 */
final readonly class ConnectedAdvertisingResponse
{
    public function __construct(
        public string $id,
        public string $advertisingState,
    ) {
    }
}
