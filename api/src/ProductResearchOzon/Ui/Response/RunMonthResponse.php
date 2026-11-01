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

    public function __construct(RunMonthState $month)
    {
        $this->period = new MonthResponse($month->period);
        $this->status = $month->status;
        $this->snapshotId = $month->snapshotId;
        $this->rowCount = $month->rowCount;
        $this->errorCode = $month->errorCode;
    }
}
