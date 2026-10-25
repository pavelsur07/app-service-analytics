<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Ingestion\Application\ServiceCoverage\CaptureServiceCoverageAction;
use App\Ingestion\Infrastructure\Persistence\DbalServiceCoverageCaptureStore;
use App\Ingestion\Infrastructure\ServiceCoverage\OzonFetcherCoverageClient;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageSnapshot;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageState;

/**
 * Сводка покрытия сервиса (ServiceCoverageCapture). Пишется тем же
 * хранилищем, что у процесса снятия: удачная попытка — с ответом
 * сервиса, неудачная — только исход поверх прежней сводки.
 */
final class ServiceCoverageCaptureBuilder
{
    private ServiceCoverageState $state = ServiceCoverageState::Ok;
    private ?string $payload = null;
    private \DateTimeImmutable $at;

    private function __construct()
    {
        $this->at = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function aCapture(): self
    {
        return new self();
    }

    /** Удачная попытка с этим ответом сервиса (контракт service-coverage v1). */
    public function withPayload(string $payload): self
    {
        $clone = clone $this;
        $clone->state = ServiceCoverageState::Ok;
        $clone->payload = $payload;

        return $clone;
    }

    /** Неудачная попытка: сервис выключен, не готов или не ответил. */
    public function failedAs(ServiceCoverageState $state): self
    {
        $clone = clone $this;
        $clone->state = $state;
        $clone->payload = null;

        return $clone;
    }

    public function withAt(\DateTimeImmutable $at): self
    {
        $clone = clone $this;
        $clone->at = $at;

        return $clone;
    }

    public function persistWith(DbalServiceCoverageCaptureStore $store): void
    {
        $snapshot = null === $this->payload
            ? ServiceCoverageSnapshot::without($this->state)
            : OzonFetcherCoverageClient::parse($this->payload);

        $store->record(CaptureServiceCoverageAction::SERVICE, $snapshot, $this->at);
    }
}
