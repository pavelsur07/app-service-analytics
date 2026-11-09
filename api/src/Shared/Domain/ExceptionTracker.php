<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** Only sanitized application exceptions, without credentials or provider responses. */
interface ExceptionTracker
{
    public function capture(\Throwable $failure): void;
}
