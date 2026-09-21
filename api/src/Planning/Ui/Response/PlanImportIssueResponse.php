<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

use OpenApi\Attributes as OA;

final readonly class PlanImportIssueResponse
{
    public function __construct(
        #[OA\Property(nullable: true)] public ?int $rowNumber,
        public string $code,
        public string $message,
    ) {
    }
}
