<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\NetForecastArtifactPublication;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository;
use App\Tests\Support\Builder\NetForecastArtifactBuilder;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

#[SkipDatabaseRollback]
final class NetForwardArtifactTest extends KernelTestCase
{
    private Connection $db;
    private string $company;
    private string $account;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $params = $connection->getParams();
        unset($params['wrapperClass']);
        $this->db = DriverManager::getConnection($params);
        $this->company = Uuid::v7()->toRfc4122();
        $this->account = Uuid::v7()->toRfc4122();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) {
            $this->db->rollBack();
        }
        $this->db->executeStatement('DELETE FROM net_forecast_artifact WHERE company_id = ? AND marketplace_account_id = ?', [$this->company, $this->account]);
        $this->db->close();
        parent::tearDown();
    }

    public function testEntityBuilderUsesAValidPublication(): void
    {
        $artifact = NetForecastArtifactBuilder::aNetForecastArtifact()->withPublication($this->candidate(Uuid::v7()->toRfc4122(), 'builder'))->build();

        self::assertInstanceOf(\App\Ingestion\Domain\NetForecastArtifact::class, $artifact);
    }

    public function testFirstCommittedWinnerImmutableAndScoped(): void
    {
        $repository = new DbalNetForecastArtifactRepository($this->db);
        $request = Uuid::v7()->toRfc4122();
        $first = $this->candidate($request, 'first');
        $second = $this->candidate($request, 'second');
        self::assertEquals($first, $repository->publishFirst($this->company, $this->account, $first, (int) (hrtime(true) + 30000000000)));
        self::assertFalse($this->db->isTransactionActive());
        self::assertEquals($first, $repository->publishFirst($this->company, $this->account, $second, (int) (hrtime(true) + 30000000000)));
        self::assertNull($repository->find($this->company, Uuid::v7()->toRfc4122(), NetForwardArtifactKind::Input, $request));
        self::assertCount(1, $repository->findBatch($this->company, $this->account, [['kind' => NetForwardArtifactKind::Input, 'requestId' => $request]]));
        $this->expectException(\DomainException::class);
        $repository->publishFirst($this->company, $this->account, new NetForecastArtifactPublication($second->root, hash('sha256', 'different-request'), 2), (int) (hrtime(true) + 30000000000));
    }

    public function testPublicationRefusesOuterTransaction(): void
    {
        $this->db->beginTransaction();
        $this->expectException(\LogicException::class);
        (new DbalNetForecastArtifactRepository($this->db))->publishFirst($this->company, $this->account, $this->candidate(Uuid::v7()->toRfc4122(), 'first'), (int) (hrtime(true) + 30000000000));
    }

    public function testPublicationConflictWaitIsBoundedByCaptureDeadline(): void
    {
        $params = $this->db->getParams();
        unset($params['wrapperClass']);
        $blocker = DriverManager::getConnection($params);
        $builder = $this->artifactBuilder(Uuid::v7()->toRfc4122(), 'blocked');
        self::assertInstanceOf(\App\Ingestion\Domain\NetForecastArtifact::class, $builder->build());
        $candidate = $builder->publication();
        $key = $candidate->root;
        $blocker->beginTransaction();
        $blocker->executeStatement('INSERT INTO net_forecast_artifact (company_id, marketplace_account_id, kind, request_id, request_hash, attempt_id, artifact_hash, manifest_byte_size, published_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)', [$this->company, $this->account, $key->kind->value, $key->requestId, $candidate->requestHash, $key->attemptId, $key->sha256, $candidate->manifestByteSize]);
        $started = hrtime(true);
        $failure = null;
        try {
            (new DbalNetForecastArtifactRepository($this->db))->publishFirst($this->company, $this->account, $candidate, (int) (hrtime(true) + 250000000));
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            $blocker->rollBack();
            $blocker->close();
        }

        self::assertInstanceOf(\Doctrine\DBAL\Exception\DriverException::class, $failure);
        self::assertLessThan(2000000000, hrtime(true) - $started);
        self::assertNull((new DbalNetForecastArtifactRepository($this->db))->find($this->company, $this->account, NetForwardArtifactKind::Input, $key->requestId));
    }

    private function candidate(string $request, string $body): NetForecastArtifactPublication
    {
        return $this->artifactBuilder($request, $body)->publication();
    }

    private function artifactBuilder(string $request, string $body): NetForecastArtifactBuilder
    {
        return NetForecastArtifactBuilder::aNetForecastArtifact()->withPublicationIdentity($this->company, $this->account, NetForwardArtifactKind::Input, $request, Uuid::v7()->toRfc4122(), hash('sha256', $body), hash('sha256', 'request'), 2);
    }
}
