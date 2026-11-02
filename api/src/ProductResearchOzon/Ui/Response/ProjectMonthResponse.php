<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Infrastructure\Query\ProjectMonthRow;
use OpenApi\Attributes as OA;

final readonly class ProjectMonthResponse
{
    public MonthResponse $period;
    #[OA\Property(enum: ['missing', 'queued', 'running', 'ready', 'error'])]
    public string $status;
    public ?int $rowCount;
    public ?string $errorCode;
    #[OA\Property(enum: ['api', 'cache'], nullable: true)]
    public ?string $origin;
    #[OA\Property(format: 'date-time', nullable: true)]
    public ?string $collectedAt;
    #[OA\Property(format: 'date-time', nullable: true)]
    public ?string $usedAt;

    public function __construct(ProjectMonthRow $row)
    {
        $this->period = new MonthResponse($row->period);
        $this->status = $row->attempt->status ?? 'missing';
        $this->rowCount = $row->attempt?->rowCount;
        $this->errorCode = $row->attempt?->errorCode;
        $metadata = $row->attempt?->provenance?->payload();
        $this->origin = $metadata['origin'] ?? null;
        $this->collectedAt = $metadata['collected_at'] ?? null;
        $this->usedAt = $metadata['used_at'] ?? null;
    }
}
