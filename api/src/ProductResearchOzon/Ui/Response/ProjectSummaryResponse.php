<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Infrastructure\Query\ResearchProjectSummaryRow;

final readonly class ProjectSummaryResponse
{
    public string $id;
    public string $categoryPath;
    public string $name;
    public int $periodCount;
    public int $version;
    public string $createdAt;
    public string $status;

    public function __construct(ResearchProjectSummaryRow $row)
    {
        $this->id = $row->id;
        $this->categoryPath = $row->categoryPath;
        $this->name = $row->name;
        $this->periodCount = $row->periodCount;
        $this->version = $row->version;
        $this->createdAt = (new \DateTimeImmutable($row->createdAt))->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
        $this->status = 'draft';
    }
}
