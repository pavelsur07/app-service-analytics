<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Колонка на company_member без системы разрешений (ADR-002) — не
 * путать с Symfony-ролями (User::getRoles(), всегда ROLE_USER).
 */
enum CompanyMemberRole: string
{
    case Owner = 'owner';
}
