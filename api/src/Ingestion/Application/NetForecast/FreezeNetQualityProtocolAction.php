<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\NetForecastArtifactRepository;
use App\Ingestion\Domain\NetForwardArtifactKind;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class FreezeNetQualityProtocolAction
{
    public function __construct(private NetQualityArtifactWriter $writer, private NetForwardArtifactReader $reader, private NetForecastArtifactRepository $repository, private ClockInterface $clock, #[Autowire('%app.version%')] private string $softwareRelease)
    {
    }

    public function __invoke(string $companyId, string $accountId, NetQualityProtocol $protocol): NetQualityArtifactRef
    {
        if ($companyId !== $protocol->companyId || $accountId !== $protocol->accountId) {
            throw new \DomainException('Protocol company or account mismatch.');
        }
        $this->reader->assertNoTransaction();
        $codec = new NetForwardManifestCodec();
        $requestHash = hash('sha256', $codec->encode(['policy' => 'net_quality_protocol_v1', 'value' => $protocol->data]));
        $budget = new NetQualityBudget();
        if (null !== $this->repository->find($companyId, $accountId, NetForwardArtifactKind::QualityProtocol, $protocol->protocolId)) {
            $winner = $this->reader->read($companyId, $accountId, NetForwardArtifactKind::QualityProtocol, $protocol->protocolId, requestHash: $requestHash, budget: $budget);

            return new NetQualityArtifactRef(NetForwardArtifactKind::QualityProtocol, $protocol->protocolId, $winner->publication->root->sha256);
        }
        $start = NetQualitySchema::utc(NetQualitySchema::string($protocol->data, 'captureFromUtc'));
        if ($start <= $this->clock->now() || !str_ends_with($this->softwareRelease, substr(NetQualitySchema::string($protocol->data, 'codeCommit'), 0, 7))) {
            throw new \DomainException('Freeze requires future captures and the deployed code commit.');
        }

        return $this->writer->publish($companyId, $accountId, new NetQualityArtifactDocument(NetForwardArtifactKind::QualityProtocol, $protocol->protocolId, $requestHash, null, ['schema' => 'net_quality_protocol_v1', 'softwareRelease' => $this->softwareRelease], [['type' => 'quality_protocol', 'row' => ['value' => $protocol->data]]]), $budget);
    }
}
