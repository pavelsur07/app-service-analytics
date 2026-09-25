<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Coverage;

/**
 * Разобранное сообщение из очереди `failed` и московский день, когда
 * оно туда попало.
 */
final readonly class FailedMessage
{
    public function __construct(
        public object $message,
        public \DateTimeImmutable $failedOn,
    ) {
    }
}
