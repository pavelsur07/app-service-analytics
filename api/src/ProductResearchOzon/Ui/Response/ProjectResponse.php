<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\Response;

use App\ProductResearchOzon\Application\ProjectMutationResult;
use App\ProductResearchOzon\Infrastructure\Query\ResearchProjectRow;

final readonly class ProjectResponse
{
    public string $id;
    public string $categoryPath;
    public int $categoryRevision;
    public string $name;
    public string $comment;
    public bool $includeCurrentMonth;
    public int $periodCount;
    public int $version;
    public string $createdByUserId;
    public string $createdAt;
    public string $updatedAt;
    public string $status;
    public bool $fbs;
    public string $currency;

    public function __construct(ResearchProjectRow $row, public ?PreviewResponse $preview = null)
    {
        $this->id = $row->id;
        $this->categoryPath = $row->categoryPath;
        $this->categoryRevision = $row->categoryRevision;
        $this->name = $row->name;
        $this->comment = $row->comment;
        $this->includeCurrentMonth = $row->includeCurrentMonth;
        $this->periodCount = $row->periodCount;
        $this->version = $row->version;
        $this->createdByUserId = $row->createdByUserId;
        $this->createdAt = (new \DateTimeImmutable($row->createdAt))->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
        $this->updatedAt = (new \DateTimeImmutable($row->updatedAt))->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM);
        $this->status = 'draft';
        $this->fbs = true;
        $this->currency = 'RUB';
    }

    public static function mutation(ProjectMutationResult $result): self
    {
        $p = $result->project;
        $row = new ResearchProjectRow($p->id()->toRfc4122(), $p->categoryPath(), $p->categoryRevision(), $p->name(), $p->comment(), $p->includeCurrentMonth(), $p->periodCount(), $p->version(), $p->createdByUserId()->toRfc4122(), $p->createdAt()->format(\DATE_ATOM), $p->updatedAt()->format(\DATE_ATOM));

        return new self($row, new PreviewResponse($result->preview, $p->categoryPath()));
    }
}
