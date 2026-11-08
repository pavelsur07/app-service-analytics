<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

final readonly class AdminCompanyAuditRow
{
    public function __construct(
        public string $id,
        public string $action,
        public string $actorType,
        public string $actorId,
        public ?string $actorEmail,
        public string $occurredAt,
    ) {
    }
}
