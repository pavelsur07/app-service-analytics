<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\Admin;

final readonly class AdminCompanyAuditResponse
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
