<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

final readonly class ConnectionsResponse
{
    /**
     * @param list<ConnectionResponse> $connections
     */
    public function __construct(
        public array $connections,
    ) {
    }
}
