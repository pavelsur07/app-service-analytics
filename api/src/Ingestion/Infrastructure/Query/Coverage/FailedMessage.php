<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Coverage;

/**
 * Разобранное сообщение из очереди `failed`: момент, когда оно туда
 * попало, и московский день этого момента.
 */
final readonly class FailedMessage
{
    public function __construct(
        public object $message,
        public \DateTimeImmutable $failedOn,
        public \DateTimeImmutable $failedAt,
    ) {
    }
}
