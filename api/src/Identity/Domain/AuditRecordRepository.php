<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Только добавление. Чтения журнала в интерфейсе нет, пока нет экрана,
 * который бы его показывал; изменения и удаления не будет никогда.
 */
interface AuditRecordRepository
{
    public function add(AuditRecord $record): void;
}
