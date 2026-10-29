<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\NetQualityArtifactDocument;
use App\Ingestion\Domain\NetForwardArtifactKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NetQualityArtifactCodecTest extends TestCase
{
    public function testUnsupportedQualitySchemaCannotBePublishedAsCurrentProtocol(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NetQualityArtifactDocument(NetForwardArtifactKind::QualityProtocol, Uuid::v7()->toRfc4122(), str_repeat('a', 64), null, ['schema' => 'net_quality_protocol_v999'], []);
    }
}
