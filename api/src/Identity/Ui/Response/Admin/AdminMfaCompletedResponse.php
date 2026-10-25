<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\Admin;

use OpenApi\Attributes as OA;

final readonly class AdminMfaCompletedResponse
{
    /** @param list<string> $recoveryCodes */
    public function __construct(
        #[OA\Property(enum: ['authenticated'])]
        public string $state,
        public array $recoveryCodes,
    ) {
    }
}
