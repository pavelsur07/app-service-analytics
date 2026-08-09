<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface CompanyMemberRepository
{
    public function add(CompanyMember $member): void;

    /**
     * Единая проверка доступа (ADR-002, ТЗ §6) — вызывается на каждом
     * запросе к company-scoped маршруту (CompanyAccessSubscriber).
     */
    public function existsForUserAndCompany(string $companyId, string $userId): bool;
}
