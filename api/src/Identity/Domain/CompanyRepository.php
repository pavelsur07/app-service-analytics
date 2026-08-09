<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface CompanyRepository
{
    public function add(Company $company): void;

    /**
     * Нет FK на company_id у ссылающихся таблиц (тот же выбор, что для
     * marketplace_account.company_id — ADR-002/ADR-003), поэтому опечатка
     * в введённом вручную companyId иначе прошла бы молча. Нужен
     * консольной команде онбординга — проверить, что компания существует,
     * до создания членства.
     */
    public function get(string $id): ?Company;
}
