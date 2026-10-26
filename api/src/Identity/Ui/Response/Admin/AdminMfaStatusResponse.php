<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\Admin;

use OpenApi\Attributes as OA;

final readonly class AdminMfaStatusResponse
{
    public function __construct(
        #[OA\Property(enum: ['enrollment_required', 'verification_required', 'authenticated'])]
        public string $state,
    ) {
    }
}
