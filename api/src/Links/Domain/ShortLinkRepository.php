<?php

declare(strict_types=1);

namespace App\Links\Domain;

use Symfony\Component\Uid\Uuid;

interface ShortLinkRepository
{
    /**
     * Возвращает false только при занятом code, чтобы вызывающий мог
     * сгенерировать новый код без закрытия EntityManager исключением.
     */
    public function tryAdd(ShortLink $link): bool;

    public function get(Uuid $id): ?ShortLink;

    public function save(): void;
}
