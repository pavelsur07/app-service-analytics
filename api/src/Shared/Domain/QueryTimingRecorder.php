<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** Technical recording port; drivers do not depend on application-level scopes or logging. */
interface QueryTimingRecorder
{
    public function enabled(): bool;

    public function recordSql(string $sql, int $nanoseconds, bool $failed): void;
}
