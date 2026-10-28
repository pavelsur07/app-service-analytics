<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use Symfony\Component\Uid\Uuid;

/** Current RR input context; a timestamp alone is not a historical manifest. */
final readonly class NetTrainingContext
{
    public const string MODEL_VERSION = 'net_training_observed_v1';
    public const string COVERAGE_POLICY = 'daily_complete_receipts_v1';

    public function __construct(
        public string $companyId,
        public string $accountId,
        public \DateTimeImmutable $readAt,
        public NetForecastCaptureEpoch $epoch,
        public ?string $snapshotId = null,
    ) {
        if (!Uuid::isValid($companyId) || !Uuid::isValid($accountId) || 0 !== $readAt->getOffset() || !$epoch->contains($readAt)) {
            throw new \InvalidArgumentException('Invalid current training context.');
        }
    }

    public function assertScope(string $companyId, string $accountId): void
    {
        if ($this->companyId !== $companyId || $this->accountId !== $accountId) {
            throw new \InvalidArgumentException('Training context belongs to another company or account.');
        }
    }

    public function fingerprint(): string
    {
        return hash('sha256', implode('|', [$this->companyId, $this->accountId, $this->readAt->format('Y-m-d\TH:i:s.u\Z'), $this->epoch->id, $this->epoch->captureVersion, $this->epoch->start->format('c'), $this->epoch->end?->format('c') ?? '', $this->epoch->cutoverRelease, $this->snapshotId ?? 'single_statement', self::MODEL_VERSION, self::COVERAGE_POLICY]));
    }
}
