<?php

declare(strict_types=1);

namespace App\Planning\Domain;

final readonly class PlanImportIssue
{
    public function __construct(
        public ?int $rowNumber,
        public string $code,
        public string $message,
    ) {
    }
}
