<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Координация действий с неподтверждённым аккаунтом и ручной уборки.
 * Shared-секция не запрещает параллельные resend/confirm, но cleanup
 * не может получить эксклюзивную блокировку посередине такого действия.
 */
interface EmailVerificationLifecycleGuard
{
    /** @param \Closure(): void $operation */
    public function runShared(\Closure $operation): void;
}
