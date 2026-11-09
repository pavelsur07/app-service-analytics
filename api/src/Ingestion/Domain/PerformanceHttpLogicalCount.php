<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;

/** Schema only: atomic SQL counters, no credentials, companies or money. */
#[ORM\Entity]
#[ORM\Table(name: 'performance_http_logical_count')]
class PerformanceHttpLogicalCount
{
    #[ORM\Id]
    #[ORM\Column(type: 'datetimetz_immutable')]
    private readonly \DateTimeImmutable $bucketStart;

    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private readonly string $operation;

    #[ORM\Column(type: 'bigint')]
    private readonly int $attempts;

    private function __construct(\DateTimeImmutable $bucketStart, string $operation, int $attempts)
    {
        $this->bucketStart = $bucketStart;
        $this->operation = $operation;
        $this->attempts = $attempts;
    }
}
