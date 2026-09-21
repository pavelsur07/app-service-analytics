<?php

declare(strict_types=1);

namespace App\Planning\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'planning_import_preview')]
#[ORM\UniqueConstraint(
    name: 'uq_planning_import_preview_ready_fingerprint',
    columns: ['company_id', 'marketplace_account_id', 'actor_id', 'fingerprint'],
    options: ['where' => "((status)::text = 'ready'::text)"],
)]
#[ORM\Index(name: 'idx_planning_import_preview_scope', columns: ['company_id', 'marketplace_account_id', 'id'])]
#[ORM\Index(name: 'idx_planning_import_preview_actor', columns: ['company_id', 'actor_id'])]
#[ORM\Index(name: 'idx_planning_import_preview_ready_expiry', columns: ['expires_at', 'id'], options: ['where' => "((status)::text = 'ready'::text)"])]
#[ORM\Index(name: 'idx_planning_import_preview_applied_cleanup', columns: ['applied_at', 'id'], options: ['where' => "((status)::text = 'applied'::text)"])]
class PlanImportPreview
{
    public const string STATUS_READY = 'ready';
    public const string STATUS_APPLIED = 'applied';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $actorId;

    #[ORM\Column(length: 64)]
    private readonly string $fingerprint;

    /** @var list<array{rowNumber: int, marketplaceSku: string, sellerArticle: ?string, businessDate: string, quantity: int, expectedVersion: int, currentQuantity: ?int, change: string}> */
    #[ORM\Column(type: 'json', options: ['jsonb' => true])]
    private array $normalizedRows;

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_READY;

    /** @var array{created: int, updated: int, unchanged: int}|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $applyResult = null;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private readonly \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $appliedAt = null;

    /** @param list<PlanImportPreviewRow> $rows */
    private function __construct(Uuid $companyId, Uuid $marketplaceAccountId, Uuid $actorId, string $fingerprint, array $rows, \DateTimeImmutable $createdAt)
    {
        if (1 !== preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw new \InvalidArgumentException('Fingerprint preview некорректен.');
        }
        foreach ($rows as $row) {
            if (!$row instanceof PlanImportPreviewRow) {
                throw new \InvalidArgumentException('Строка preview некорректна.');
            }
        }
        $this->id = Uuid::v7();
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->actorId = $actorId;
        $this->fingerprint = $fingerprint;
        $this->normalizedRows = array_map(static fn (PlanImportPreviewRow $row): array => $row->toArray(), $rows);
        $this->createdAt = $createdAt;
        $this->expiresAt = $createdAt->modify('+24 hours');
    }

    /** @param list<PlanImportPreviewRow> $rows */
    public static function create(Uuid $companyId, Uuid $marketplaceAccountId, Uuid $actorId, string $fingerprint, array $rows, \DateTimeImmutable $createdAt): self
    {
        return new self($companyId, $marketplaceAccountId, $actorId, $fingerprint, $rows, $createdAt);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function actorId(): Uuid
    {
        return $this->actorId;
    }

    public function fingerprint(): string
    {
        return $this->fingerprint;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function appliedAt(): ?\DateTimeImmutable
    {
        return $this->appliedAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** @return list<PlanImportPreviewRow> */
    public function rows(): array
    {
        return array_map(static fn (array $row): PlanImportPreviewRow => PlanImportPreviewRow::fromArray($row), $this->normalizedRows);
    }

    /** @param array{created: int, updated: int, unchanged: int} $result */
    public function markApplied(array $result, \DateTimeImmutable $appliedAt): void
    {
        if (self::STATUS_READY !== $this->status) {
            throw new \LogicException('Preview уже применён.');
        }
        $this->status = self::STATUS_APPLIED;
        $this->applyResult = $result;
        $this->appliedAt = $appliedAt;
        $this->normalizedRows = [];
    }

    /** @return array{created: int, updated: int, unchanged: int}|null */
    public function result(): ?array
    {
        return $this->applyResult;
    }
}
