<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface ExtensionTokenRepository
{
    public function add(ExtensionToken $token): void;

    public function save(ExtensionToken $token): void;

    /**
     * Отзыв одним условным UPDATE, а не «прочитать, проверить, записать»:
     * последнее два параллельных запроса проходят оба, и второй затирает
     * первого отзывающего (CLAUDE.md §4 — та же причина, по которой
     * «найти, и если не найдено — записать» не является защитой).
     *
     * Возвращает true, если отозвал именно этот вызов; false — если токен
     * уже был отозван раньше. Оба случая для вызывающего успешны: отзыв
     * идемпотентен, различие нужно только чтобы не переписывать след.
     */
    public function revokeIfActive(string $companyId, Uuid $id, Uuid $revokedByUserId, \DateTimeImmutable $at): bool;

    /**
     * $companyId первым параметром (CLAUDE.md §1), без исключений —
     * поиск токена по одному лишь id запрещён.
     *
     * Проверяющая сторона ищет не здесь: ей нужен поиск по хэшу, до того
     * как компания известна, и это межарендаторное чтение по своей природе.
     * Сущность companyId имеет, поэтому по CLAUDE.md §1 такое чтение живёт
     * отдельным DBAL-запросом вне этого интерфейса — ExtensionTokenByHashQuery,
     * тот же приём, что у ActiveOzonAccountsQuery, — а не методом,
     * снимающим скоуп с company-scoped интерфейса.
     */
    public function get(string $companyId, Uuid $id): ?ExtensionToken;
}
