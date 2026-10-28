<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTargetReturnTrace
{
    public function __construct(
        public string $sourceRowId,
        public ?string $firstRevisionId,
        public ?string $latestRevisionId,
        public int $latestFactRevision,
        public ?\DateTimeImmutable $latestCapturedAt,
        public string $rawDocumentId,
        public ?string $rawBodyHash,
        public string $returnType,
        public int $quantity,
    ) {
        if (null !== $latestCapturedAt && 0 !== $latestCapturedAt->getOffset()) {
            throw new \InvalidArgumentException('Target timestamps must be UTC.');
        }
    }
}
