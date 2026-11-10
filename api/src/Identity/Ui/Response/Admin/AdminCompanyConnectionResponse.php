<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\Admin;

final readonly class AdminCompanyConnectionResponse
{
    public function __construct(
        public string $id,
        public string $marketplace,
        public string $externalShopId,
        public string $state,
        public ?string $advertisingState,
        public string $createdAt,
    ) {
    }
}
