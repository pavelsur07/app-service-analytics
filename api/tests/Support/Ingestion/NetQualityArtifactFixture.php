<?php

declare(strict_types=1);

namespace App\Tests\Support\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;

final readonly class NetQualityArtifactFixture
{
    public Net\NetForwardArtifactReader $reader;
    public Net\NetQualityArtifactWriter $writer;
    public Net\PublishNetForecastCaptureAction $publisher;
    public Net\NetForwardManifestCodec $codec;
    public MemoryNetForwardArtifactStorage $storage;
    public MockClock $clock;

    public function __construct(public Connection $db)
    {
        $this->codec = new Net\NetForwardManifestCodec();
        $this->storage = new MemoryNetForwardArtifactStorage();
        $this->clock = new MockClock('2027-01-01T00:00:00Z');
        $repository = new DbalNetForecastArtifactRepository($db);
        $this->reader = new Net\NetForwardArtifactReader($db, $repository, $this->storage, $this->codec);
        $this->publisher = new Net\PublishNetForecastCaptureAction($repository, $this->storage, $this->reader);
        $this->writer = new Net\NetQualityArtifactWriter($this->reader, $this->publisher, $this->codec, $this->clock);
    }

    public function cleanup(string $companyId, string $accountId): void
    {
        $this->db->executeStatement('DELETE FROM net_forecast_artifact WHERE company_id = ? AND marketplace_account_id = ?', [$companyId, $accountId]);
    }
}
