<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\Admin;

final readonly class AdminCompanyMemberResponse
{
    public function __construct(
        public string $id,
        public string $email,
        public string $role,
        public string $joinedAt,
        public ?string $emailConfirmedAt,
    ) {
    }
}
