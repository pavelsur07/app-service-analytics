<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface CompanyRepository
{
    public function add(Company $company): void;

    /**
     * Переход `active` → `blocked` (ADR-017). Возвращает false, если
     * компания уже была заблокирована: повторный вызов ничего не меняет
     * и второй записи в журнале не создаёт.
     *
     * Условие перехода живёт внутри самого `UPDATE`, а не в проверке
     * перед ним — тот же приём и та же причина, что у
     * `ExtensionTokenRepository::revokeIfActive` (ADR-011 п.4,
     * CLAUDE.md §4): два параллельных запроса прошли бы проверку оба,
     * каждый в своей транзакции, и в журнале оказалось бы два следа
     * одного перехода.
     *
     * След принимается параметром, а не пишется вызывающим отдельно:
     * статус, изменённый без записи в журнале, — это и есть отсутствие
     * журнала (ADR-011). Обе записи фиксирует одна транзакция.
     */
    public function blockIfActive(string $companyId, AuditRecord $trail): bool;

    /**
     * Обратный переход `blocked` → `active`. Симметричен по всем
     * свойствам: условие в `UPDATE`, след той же транзакцией, false
     * при повторе.
     */
    public function activateIfBlocked(string $companyId, AuditRecord $trail): bool;
}
