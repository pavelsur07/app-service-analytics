<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository;
use App\Tests\Support\Builder\NetQualityProtocolBuilder;
use App\Tests\Support\Ingestion\NetQualityArtifactFixture;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class NetQualityInventoryTest extends KernelTestCase
{
    public function testEveryScheduledSlotIsSealedEvenWhenCaptureIsMissing(): void
    {
        self::bootKernel(['debug' => false]);
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $f = new NetQualityArtifactFixture(DriverManager::getConnection($params));
        $protocol = NetQualityProtocolBuilder::aProtocol()->build();
        try {
            $repository = new DbalNetForecastArtifactRepository($f->db);
            $ref = (new Net\FreezeNetQualityProtocolAction($f->writer, $f->reader, $repository, $f->clock, '123-aaaaaaa', str_repeat('a', 40)))($protocol->companyId, $protocol->accountId, $protocol);
            $slots = array_map(static fn (array $slot): array => ['requestId' => $slot['requestId'], 'status' => 'missing', 'artifact' => null, 'reason' => 'not_captured'], $protocol->slots);
            $data = ['requestId' => Uuid::v7()->toRfc4122(), 'protocol' => $ref->toArray(), 'subtype' => 'capture', 'cutoffUtc' => $protocol->data['captureToUtc'], 'slots' => $slots];
            $f->clock->modify('+20 days');
            $seal = new Net\SealNetQualityInventoryAction($f->writer, $f->reader, new Net\NetQualityProtocolReader($f->reader), $repository, $f->clock);
            $inventoryRef = $seal($protocol->companyId, $protocol->accountId, new Net\NetQualityInventoryRequest($data));
            $inventory = (new Net\NetQualityInventoryReader($f->reader))->read($protocol->companyId, $protocol->accountId, $inventoryRef, new Net\NetQualityBudget());
            self::assertInstanceOf(Net\NetQualityCaptureInventory::class, $inventory);
            self::assertCount(14, $inventory->slots);
            self::assertSame('missing', $inventory->slots[0]['status']);
            array_pop($slots);
            $data['requestId'] = Uuid::v7()->toRfc4122();
            $data['slots'] = $slots;
            $this->expectException(\DomainException::class);
            $seal($protocol->companyId, $protocol->accountId, new Net\NetQualityInventoryRequest($data));
        } finally {
            $f->cleanup($protocol->companyId, $protocol->accountId);
            $f->db->close();
        }
    }
}
