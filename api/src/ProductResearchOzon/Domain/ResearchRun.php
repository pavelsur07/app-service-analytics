<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

use App\ProductResearchOzon\Domain\ValueObject\RunMonthState;
use App\ProductResearchOzon\Domain\ValueObject\RunProgress;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Human run intent and transactional outbox share one immutable input record. */
#[ORM\Entity]
#[ORM\Table(name: 'product_research_ozon_run')]
#[ORM\UniqueConstraint(name: 'uq_research_run_request', columns: ['company_id', 'project_id', 'request_id'])]
#[ORM\UniqueConstraint(name: 'uq_research_active_run', columns: ['company_id', 'project_id'], options: ['where' => '(finished_at IS NULL)'])]
#[ORM\Index(name: 'idx_research_run_author', columns: ['company_id', 'created_by_user_id'])]
#[ORM\Index(name: 'idx_research_run_project', columns: ['company_id', 'project_id', 'id'])]
#[ORM\Index(name: 'idx_research_run_delivery', columns: ['company_id', 'next_delivery_at', 'id'], options: ['where' => '(delivered_at IS NULL)'])]
#[ORM\Index(name: 'idx_research_run_poll', columns: ['company_id', 'next_poll_at', 'id'], options: ['where' => '((delivered_at IS NOT NULL) AND (finished_at IS NULL))'])]
class ResearchRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;
    #[ORM\ManyToOne(targetEntity: ResearchProject::class)]
    #[ORM\JoinColumn(name: 'company_id', referencedColumnName: 'company_id', nullable: false)]
    #[ORM\JoinColumn(name: 'project_id', referencedColumnName: 'id', nullable: false)]
    private ResearchProject $project;
    #[ORM\Column(type: 'uuid')]
    private Uuid $sourceId;
    #[ORM\Column(type: 'uuid')]
    private Uuid $requestId;
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $inputHash;
    #[ORM\Column(type: 'text')]
    private string $payload;
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $payloadHash;
    #[ORM\Column(type: 'uuid')]
    private Uuid $createdByUserId;
    #[ORM\Column(type: 'datetimetz_immutable', columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE NOT NULL')]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column(length: 16)]
    private string $status = 'queued';
    #[ORM\Column]
    private int $revision = 1;
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $progressHash;
    #[ORM\Column]
    private int $deliveryAttempts = 0;
    #[ORM\Column(type: 'datetimetz_immutable', columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE NOT NULL')]
    private \DateTimeImmutable $nextDeliveryAt;
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true, columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE NOT NULL')]
    private ?\DateTimeImmutable $deliveredAt = null;
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true, columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE NOT NULL')]
    private ?\DateTimeImmutable $nextPollAt = null;
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true, columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE NOT NULL')]
    private ?\DateTimeImmutable $finishedAt = null;
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $leaseId = null;
    #[ORM\Column(type: 'datetimetz_immutable', nullable: true, columnDefinition: 'TIMESTAMP(6) WITH TIME ZONE NOT NULL')]
    private ?\DateTimeImmutable $leaseUntil = null;
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $errorCode = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $progressFormat = 2;

    /** @param list<MonthSlice> $months */
    public function __construct(ResearchProject $project, Uuid $source, Uuid $requestId, string $inputHash, array $months, Uuid $actor, \DateTimeImmutable $at)
    {
        $this->id = Uuid::v7();
        $this->project = $project;
        $this->companyId = $project->companyId();
        $this->sourceId = $source;
        $this->requestId = $requestId;
        $this->inputHash = $inputHash;
        $this->createdByUserId = $actor;
        $this->createdAt = $at->setTimezone(new \DateTimeZone('UTC'));
        $this->nextDeliveryAt = $this->createdAt;
        $initial = new RunProgress($project->companyId()->toRfc4122(), $this->id->toRfc4122(), $source->toRfc4122(), 'queued', 1, array_map(static fn (MonthSlice $month): RunMonthState => new RunMonthState($month, 'queued', null, null, null), $months));
        $this->progressHash = $initial->hash();
        $payload = ['schema_version' => '1', 'company_id' => $initial->companyId, 'run_id' => $initial->runId, 'project_id' => $project->id()->toRfc4122(), 'source_connection_id' => $initial->sourceId, 'category_path' => $project->categoryPath(), 'fbs' => true, 'currency' => 'RUB', 'months' => array_map(static fn (RunMonthState $month): array => $month->periodPayload(), $initial->months)];
        $this->payload = json_encode($payload, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $this->payloadHash = hash('sha256', $this->payload);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function projectId(): Uuid
    {
        return $this->project->id();
    }

    public function sourceId(): Uuid
    {
        return $this->sourceId;
    }

    public function payload(): string
    {
        return $this->payload;
    }
}
