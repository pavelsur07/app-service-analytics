<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Private mapped fields support ORM refresh under row locks; mutations stay explicit. */
#[ORM\Entity]
#[ORM\Table(name: 'product_research_ozon_project')]
#[ORM\Index(name: 'idx_research_project_author', columns: ['company_id', 'created_by_user_id'])]
class ResearchProject
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;
    #[ORM\Column(length: 2048)]
    private string $categoryPath;
    #[ORM\Column]
    private int $categoryRevision;
    #[ORM\Column(length: 200)]
    private string $name;
    #[ORM\Column(length: 2000)]
    private string $comment;
    #[ORM\Column]
    private bool $includeCurrentMonth;
    #[ORM\Column(length: 16)]
    private string $status;
    #[ORM\Column(type: 'uuid')]
    private Uuid $createdByUserId;
    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;
    #[ORM\Column]
    private int $periodCount;
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $lastRunId = null;
    /** @var list<array{month: string, date_from: string, date_to: string, days: int, partial: bool}> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true, 'default' => '[]'])]
    private array $requirements = [];

    /** @param list<MonthSlice> $months */
    public function freezeRequirements(array $months): void
    {
        if ([] === $months || \count($months) > 24) {
            throw new \InvalidArgumentException('month_limit');
        }
        $this->requirements = array_map(static fn (MonthSlice $month): array => ['month' => $month->month, 'date_from' => $month->dateFrom, 'date_to' => $month->dateTo, 'days' => $month->days, 'partial' => $month->partial], $months);
    }

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(Uuid $companyId, CategoryReference $category, string $name, string $comment, bool $includeCurrentMonth, Uuid $actor, \DateTimeImmutable $at, int $periodCount)
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > 200 || mb_strlen($comment) > 2000 || $periodCount < 1 || $periodCount > 24) {
            throw new \InvalidArgumentException('invalid_project');
        }
        $this->id = Uuid::v7();
        $this->companyId = $companyId;
        $this->categoryPath = $category->path;
        $this->categoryRevision = $category->revision;
        $this->name = $name;
        $this->comment = $comment;
        $this->includeCurrentMonth = $includeCurrentMonth;
        $this->status = 'draft';
        $this->createdByUserId = $actor;
        $this->createdAt = $at->setTimezone(new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
        $this->periodCount = $periodCount;
    }

    public function recordPeriodsChanged(int $count, bool $includeCurrentMonth, \DateTimeImmutable $at): void
    {
        if ($count < $this->periodCount || $count > 24) {
            throw new \InvalidArgumentException('period_limit');
        }
        // Count/flag force a dirty header even when the controlled clock is unchanged.
        $this->periodCount = $count;
        $this->includeCurrentMonth = $includeCurrentMonth;
        $this->updatedAt = $at->setTimezone(new \DateTimeZone('UTC'));
    }

    public function recordRunRequested(Uuid $runId, \DateTimeImmutable $at): void
    {
        $this->lastRunId = $runId;
        $this->updatedAt = $at->setTimezone(new \DateTimeZone('UTC'));
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function categoryPath(): string
    {
        return $this->categoryPath;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function comment(): string
    {
        return $this->comment;
    }

    public function includeCurrentMonth(): bool
    {
        return $this->includeCurrentMonth;
    }

    public function categoryRevision(): int
    {
        return $this->categoryRevision;
    }

    public function periodCount(): int
    {
        return $this->periodCount;
    }

    public function createdByUserId(): Uuid
    {
        return $this->createdByUserId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }
}
