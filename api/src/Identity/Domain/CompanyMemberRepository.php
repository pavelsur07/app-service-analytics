<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\CompanyStatus;

interface CompanyMemberRepository
{
    public function add(CompanyMember $member): void;

    /**
     * Только членство, без статуса компании. Вызывается
     * аутентификационной границей контура расширения
     * (`ExtensionTokenHandler`), где решается, чей это токен, а не
     * можно ли сейчас работать.
     *
     * Не путать с findAccessStatus ниже: разделены намеренно.
     * Если бы этот метод начал заодно требовать активной компании,
     * отказ заблокированному аккаунту приходил бы как 401 на этапе
     * аутентификации — «токен недействителен» вместо «аккаунт
     * выключен».
     */
    public function existsForUserAndCompany(string $companyId, string $userId): bool;

    /**
     * Единая проверка доступа к company-scoped маршруту (ADR-002, ТЗ §6,
     * ADR-017) — вызывается на каждом таком запросе
     * (`CompanyAccessSubscriber`).
     *
     * `null` — пользователь не участник; иначе статус его компании.
     * Одним запросом, а не двумя: проверка стоит на пути каждого
     * запроса, и второй round-trip здесь платится всеми экранами сразу.
     *
     * Статус возвращается только тому, чьё членство подтверждено тем же
     * запросом, — поэтому различие «не участник» и «аккаунт выключен»
     * ничего не сообщает постороннему.
     */
    public function findAccessStatus(string $companyId, string $userId): ?CompanyStatus;
}
