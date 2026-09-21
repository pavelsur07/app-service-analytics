<?php

declare(strict_types=1);

namespace App\Planning\Ui\Response;

use App\Planning\Domain\PlanImportPreview;
use App\Planning\Infrastructure\Import\PlanImportIssue;
use OpenApi\Attributes as OA;

final readonly class PlanImportPreviewResponse
{
    /**
     * @param list<PlanImportRowResponse>   $items
     * @param list<PlanImportIssueResponse> $issues
     */
    public function __construct(
        #[OA\Property(nullable: true)] public ?string $previewId,
        #[OA\Property(format: 'date-time', nullable: true)] public ?string $expiresAt,
        public PlanImportSummaryResponse $summary,
        #[OA\Property(type: 'array', items: new OA\Items(ref: PlanImportRowResponse::class))] public array $items,
        #[OA\Property(type: 'array', items: new OA\Items(ref: PlanImportIssueResponse::class))] public array $issues,
    ) {}

    public static function ready(PlanImportPreview $preview): self
    {
        $rows = $preview->rows();
        $counts = array_count_values(array_map(static fn ($row): string => $row->change, $rows));

        return new self(
            $preview->id()->toRfc4122(), $preview->expiresAt()->format(DATE_ATOM),
            new PlanImportSummaryResponse(\count($rows), $counts['new'] ?? 0, $counts['changed'] ?? 0, $counts['unchanged'] ?? 0),
            array_map(PlanImportRowResponse::fromRow(...), $rows), [],
        );
    }

    /** @param list<PlanImportIssue> $issues */
    public static function invalid(array $issues): self
    {
        return new self(null, null, new PlanImportSummaryResponse(0, 0, 0, 0), [], array_map(
            static fn (PlanImportIssue $issue): PlanImportIssueResponse => new PlanImportIssueResponse($issue->rowNumber, $issue->code, $issue->message),
            $issues,
        ));
    }
}
