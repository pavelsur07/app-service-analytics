<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion;

use App\Ingestion\Application\NetForecast\NetForwardManifestCodec;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NetForwardManifestCodecTest extends TestCase
{
    public function testSealHasCanonicalIntegrityAndRejectsTamper(): void
    {
        $codec = new NetForwardManifestCodec();
        $bytes = $codec->encode(['value' => '120', 'missing' => null]);
        self::assertSame($bytes, $codec->encode(['missing' => null, 'value' => '120']));
        self::assertSame(['missing' => null, 'value' => '120'], $codec->decode($bytes));
        $this->expectException(\UnexpectedValueException::class);
        $codec->decode(str_replace('120', '121', $bytes));
    }

    public function testUnknownVersionAndFieldsFailClosed(): void
    {
        $codec = new NetForwardManifestCodec();
        $this->expectException(\UnexpectedValueException::class);
        $codec->decode(str_replace('net_forward_manifest_v1', 'net_forward_manifest_v2', $codec->encode([])));
    }

    public function testKeyCannotReadForeignAccountOrAcceptPath(): void
    {
        $company = Uuid::v7()->toRfc4122();
        $account = Uuid::v7()->toRfc4122();
        $key = new NetForwardArtifactKey($company, $account, NetForwardArtifactKind::Input, Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), hash('sha256', '{}'));
        $key->assertScope($company, $account);
        self::assertStringStartsWith('conwix/monolith/net-forecast/v1/companies/'.$company.'/accounts/'.$account.'/', $key->toString());
        $this->expectException(\InvalidArgumentException::class);
        $key->assertScope($company, Uuid::v7()->toRfc4122());
    }

    public function testByteLimitUsesUtf8BytesAndRejectsFloats(): void
    {
        $codec = new NetForwardManifestCodec();
        $this->expectException(\InvalidArgumentException::class);
        $codec->encode(['money' => 1.5]);
    }
}
