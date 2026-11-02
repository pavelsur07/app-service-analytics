<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use OpenApi\Attributes as OA;

final readonly class RunMonthResponse
{
    public MonthResponse $period;
    #[OA\Property(enum: ['queued', 'running', 'ready', 'error'])]
    public string $status;
    public ?string $snapshotId;
    public ?int $rowCount;
    public ?string $errorCode;

    #[OA\Property(enum: ['api', 'cache'], nullable: true)]
    public ?string $origin;
    #[OA\Property(format: 'date-time', nullable: true)]
    public ?string $collectedAt;
    #[OA\Property(format: 'date-time', nullable: true)]
    public ?string $usedAt;

    public function __construct(RunMonthState $month)
    {
        $this->period = new MonthResponse($month->period);
        $this->status = $month->status;
        $this->snapshotId = $month->snapshotId;
        $this->rowCount = $month->rowCount;
        $this->errorCode = $month->errorCode;
        $metadata = $month->provenance?->payload();
        $this->origin = $metadata['origin'] ?? null;
        $this->collectedAt = $metadata['collected_at'] ?? null;
        $this->usedAt = $metadata['used_at'] ?? null;
    }
}
