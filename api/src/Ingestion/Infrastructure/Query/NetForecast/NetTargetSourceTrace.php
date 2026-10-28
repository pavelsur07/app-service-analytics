<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

final readonly class NetTargetSourceTrace
{
    /** @param list<array{postingNumber: string, rawDocumentId: string}> $statusWitnessIds
     * @param list<string> $receiptWitnessIds */
    public function __construct(
        public string $sourceRowId,
        public ?string $firstRevisionId,
        public ?string $latestRevisionId,
        public int $latestFactRevision,
        public ?\DateTimeImmutable $initialCapturedAt,
        public ?\DateTimeImmutable $latestCapturedAt,
        public string $rawDocumentId,
        public ?string $rawBodyHash,
        public ?string $amountMinor,
        public ?string $currency,
        public array $statusWitnessIds,
        public array $receiptWitnessIds,
        public ?string $coverageWitnessDigest,
    ) {
        if (null !== $initialCapturedAt && 0 !== $initialCapturedAt->getOffset()) {
            throw new \InvalidArgumentException('Target timestamps must be UTC.');
        }
        if (null !== $latestCapturedAt && 0 !== $latestCapturedAt->getOffset()) {
            throw new \InvalidArgumentException('Target timestamps must be UTC.');
        }
    }
}
