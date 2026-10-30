<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\NetQualityArtifactRef;
use App\Ingestion\Domain\NetForwardArtifactKind;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 3).'/bin/net-forecast-quality-bridge.php';

final class NetQualityOperatorRefTest extends TestCase
{
    public function testCanonicalManifestKeyOrderDoesNotChangeBinding(): void
    {
        $id = '01900000-0000-7000-8000-000000000001';
        $hash = str_repeat('a', 64);
        $ref = new NetQualityArtifactRef(NetForwardArtifactKind::QualityProtocol, $id, $hash);
        self::assertTrue(netQualitySameRef(['artifactHash' => $hash, 'kind' => 'quality-protocol', 'requestId' => $id], $ref));
        self::assertFalse(netQualitySameRef(['artifactHash' => str_repeat('b', 64), 'kind' => 'quality-protocol', 'requestId' => $id], $ref));
        self::assertFalse(netQualitySameRef(null, $ref));
    }
}
