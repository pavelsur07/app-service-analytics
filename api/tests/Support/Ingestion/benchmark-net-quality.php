<?php

declare(strict_types=1);

namespace App\Tests\Support\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\NetForecastArtifactPublication;
use App\Ingestion\Domain\NetForwardArtifactKey;
use App\Ingestion\Domain\NetForwardArtifactKind as Kind;
use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Domain\ValueObject\NetForwardTruthResult;
use App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository;
use App\Kernel;
use App\Tests\Support\Builder\NetQualityObservationBuilder;
use App\Tests\Support\Builder\NetQualityProtocolBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;

require_once \dirname(__DIR__, 3).'/vendor/autoload.php';

/** Evaluator/selector-only saturated synthetic profile; no model, S3 latency or quality claim. */
final readonly class NetQualityResourceProfile
{
    public function __construct(private Net\NetQualityProtocol $protocol, private Net\PublishNetForecastCaptureAction $publish, private Net\NetForwardManifestCodec $codec, private Net\NetForwardInputCodec $inputs)
    {
    }

    /** @param iterable<array{type: string, row: array<string, mixed>}> $rows
     * @param array<string, mixed> $metadata */
    public function raw(Kind $kind, string $id, \DateTimeImmutable $at, ?Net\NetQualityArtifactRef $parent, iterable $rows, array $metadata): Net\NetQualityArtifactRef
    {
        $spool = new Net\NetForwardSpool($this->codec);
        foreach ($rows as $row) {
            $spool->append($row['type'], $row['row']);
        }
        $attempt = Uuid::v7()->toRfc4122();
        $hash = hash('sha256', $id);
        $body = $this->codec->encode(['companyId' => $this->protocol->companyId, 'marketplaceAccountId' => $this->protocol->accountId, 'kind' => $kind->value, 'requestId' => $id, 'requestHash' => $hash, 'attemptId' => $attempt, 'readAtUtc' => $at->format('Y-m-d\TH:i:s.u\Z'), 'parent' => null === $parent ? null : ['requestId' => $parent->requestId, 'artifactHash' => $parent->sha256], 'chunks' => $spool->descriptors(), 'metadata' => $metadata]);
        $publication = new NetForecastArtifactPublication(new NetForwardArtifactKey($this->protocol->companyId, $this->protocol->accountId, $kind, $id, $attempt, hash('sha256', $body)), $hash, \strlen($body));
        $artifact = ($this->publish)($this->protocol->companyId, $this->protocol->accountId, new Net\PreparedNetForwardArtifact($publication, $body, $spool));

        return new Net\NetQualityArtifactRef($kind, $id, $artifact->publication->root->sha256);
    }

    /** @return \Generator<int, Net\NetQualityObservation> */
    public function observations(Net\NetQualityArtifactRef $input, int $ordinal): \Generator
    {
        $slot = $this->protocol->slots[$ordinal];
        $at = Net\NetQualitySchema::utc($slot['scheduledAtUtc']);
        $day = max('2027-01-02', min('2027-07-03', $at->modify('-'.[2, 4, 7, 12][$ordinal % 4].' days')->format('Y-m-d')));
        $group = Net\NetQualityObservation::orderGroup($day, $at) ?? throw new \LogicException('Profile dates outside order groups.');
        for ($j = 0; $j < 102 + ($ordinal < 8 ? 1 : 0); ++$j) {
            $id = 'case-'.$ordinal.'-'.$j;
            $part = ['sourceRowId' => $id, 'rawQuantity' => 10, 'model' => ['numerator' => '9', 'denominator' => '1'], 'simple' => ['numerator' => '541', 'denominator' => '60'], 'modelReason' => null, 'simpleReason' => null, 'unitPriceMinor' => '7', 'currency' => 'RUB', 'truthScope' => 'part'];
            yield NetQualityObservationBuilder::anObservation()->with('companyId', $this->protocol->companyId)->with('accountId', $this->protocol->accountId)->with('orderNumber', $id)->with('businessDate', $day)->with('readAtUtc', $slot['scheduledAtUtc'])->with('input', $input->toArray())->with('group', $group)->with('components', [$id])->with('payload', ['parts' => [$part]])->build();
        }
    }

    /** @return \Generator<int, array{type: string, row: array<string, mixed>}> */
    public function truthRows(Net\NetQualityArtifactRef $input, int $ordinal): \Generator
    {
        foreach ($this->observations($input, $ordinal) as $o) {
            yield ['type' => 'truth_part', 'row' => ['sourceRowId' => $o->components[0], 'orderNumber' => $o->orderNumber, 'sku' => $o->sku, 'day' => $o->businessDate, 'unitPriceMinor' => '7', 'currency' => 'RUB', 'result' => $this->inputs->pack(new NetForwardTruthResult('ready', 9, null, Net\NetQualitySchema::utc($o->readAtUtc)->modify('+67 days')))]];
        }
        yield ['type' => 'truth_evidence', 'row' => ['values' => ['captured_at' => '2026-10-28T05:54:45.000000Z']]];
        yield ['type' => 'truth_summary', 'row' => ['state' => 'ready']];
    }
}

if ('test' !== getenv('APP_ENV') || '0' !== getenv('APP_DEBUG')) {
    throw new \RuntimeException('Requires explicit APP_ENV=test APP_DEBUG=0 and disposable test DB.');
}
(new Dotenv())->bootEnv(\dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', false);
$kernel->boot();
$registry = $kernel->getContainer()->get('doctrine');
if (!$registry instanceof ManagerRegistry) {
    throw new \LogicException('Missing doctrine registry.');
}
$connection = $registry->getConnection();
if (!$connection instanceof Connection) {
    throw new \LogicException('Missing DBAL connection.');
}
$params = $connection->getParams();
unset($params['wrapperClass']);
$db = DriverManager::getConnection($params);
$database = $db->fetchOne('SELECT current_database()');
if (!\is_string($database) || 1 !== preg_match('/^app_test(?:[1-9][0-9]*)?$/D', $database)) {
    throw new \RuntimeException('Profile refuses a non-test database.');
}
$slots = $truthSlots = [];
$start = new \DateTimeImmutable('2027-01-04T09:00:00Z');
for ($i = 0; $i < 196; ++$i) {
    $id = Uuid::v7()->toRfc4122();
    $at = $start->modify('+'.$i.' days');
    $slots[] = ['requestId' => $id, 'scheduledAtUtc' => $at->format('Y-m-d\TH:i:s.u\Z')];
    $truthSlots[] = ['requestId' => Uuid::v7()->toRfc4122(), 'inputId' => $id, 'scheduledAtUtc' => $at->modify('+70 days')->format('Y-m-d\TH:i:s.u\Z')];
    if ($i < 4) {
        $truthSlots[] = ['requestId' => Uuid::v7()->toRfc4122(), 'inputId' => $id, 'scheduledAtUtc' => $at->modify('+71 days')->format('Y-m-d\TH:i:s.u\Z')];
    }
}
$protocol = NetQualityProtocolBuilder::aProtocol()->with('from', '2027-01-01')->with('to', '2027-07-03')->with('calibrationFrom', '2027-01-01')->with('calibrationTo', '2027-01-01')->with('holdoutFrom', '2027-01-02')->with('holdoutTo', '2027-07-03')->with('captureToUtc', '2027-07-18T21:00:00.000000Z')->with('truthCutoffUtc', '2027-10-17T00:00:00.000000Z')->with('slots', $slots)->with('truthSlots', $truthSlots)->build();
$codec = new Net\NetForwardManifestCodec();
$inputCodec = new Net\NetForwardInputCodec();
$repository = new DbalNetForecastArtifactRepository($db);
$storage = new DiskNetForwardArtifactStorage();
$reader = new Net\NetForwardArtifactReader($db, $repository, $storage, $codec);
$publisher = new Net\PublishNetForecastCaptureAction($repository, $storage, $reader);
$clock = new MockClock('2027-01-01T00:00:00Z');
$writer = new Net\NetQualityArtifactWriter($reader, $publisher, $codec, $clock);
$protocols = new Net\NetQualityProtocolReader($reader);
$inventories = new Net\NetQualityInventoryReader($reader);
$sealer = new Net\SealNetQualityInventoryAction($writer, $reader, $protocols, $repository, $clock);
$company = $protocol->companyId;
$account = $protocol->accountId;
$receipt = ['status' => 'incomplete', 'fixtureOnly' => true, 'scope' => 'selector_evaluator_synthetic_disk_adapter'];
try {
    $protocolRef = (new Net\FreezeNetQualityProtocolAction($writer, $reader, $repository, $clock, '123-aaaaaaa'))($company, $account, $protocol);
    $profile = new NetQualityResourceProfile($protocol, $publisher, $codec, $inputCodec);
    $inputs = [];
    $inputReceipts = [];
    foreach ($slots as $slot) {
        $ref = $profile->raw(Kind::Input, $slot['requestId'], Net\NetQualitySchema::utc($slot['scheduledAtUtc']), null, [['type' => 'profile_only', 'row' => ['fixture' => 'minimal_frames_not_model_output']]], ['qualityProtocol' => ['protocol' => $protocolRef->toArray()]]);
        $inputs[$ref->requestId] = $ref;
        $inputReceipts[] = ['requestId' => $ref->requestId, 'status' => 'success', 'artifact' => $ref->toArray(), 'reason' => null];
    }
    $clock->modify('2027-07-19T00:00:00Z');
    $captureInventory = $sealer($company, $account, new Net\NetQualityInventoryRequest(['requestId' => Uuid::v7()->toRfc4122(), 'protocol' => $protocolRef->toArray(), 'subtype' => 'capture', 'cutoffUtc' => $protocol->data['captureToUtc'], 'slots' => $inputReceipts]));
    $candidates = static function () use ($inputs, $profile): \Generator {
        foreach (array_values($inputs) as $ordinal => $ref) {
            yield from $profile->observations($ref, $ordinal);
        }
    };
    $selectionBudget = new Net\NetQualityBudget();
    $selectionStart = hrtime(true);
    $segments = static function () use ($candidates, $selectionBudget): \Generator {
        foreach ((new Net\NetQualitySelection())->select($candidates(), $selectionBudget) as $observation) {
            yield ['type' => 'quality_selection', 'row' => ['value' => $observation->toArray()]];
        }
        yield ['type' => 'selection_summary', 'row' => ['candidateObservations' => $selectionBudget->summary()['candidates']]];
    };
    $selection = $writer->publish($company, $account, new Net\NetQualityArtifactDocument(Kind::QualitySelection, Uuid::v7()->toRfc4122(), hash('sha256', 'profile-selection'), $captureInventory, ['schema' => 'net_quality_selection_v1', 'protocol' => $protocolRef->toArray(), 'inventory' => $captureInventory->toArray()], $segments()), $selectionBudget);
    $receipt['selectionWallMs'] = intdiv(hrtime(true) - $selectionStart, 1000000);
    $receipt['selectionResources'] = $selectionBudget->summary();
    $truthReceipts = [];
    $ordinals = array_flip(array_keys($inputs));
    $epoch = new NetForecastCaptureEpoch(Net\NetQualitySchema::string($protocol->data, 'epochId'), NetForecastCaptureEpoch::CAPTURE_VERSION, Net\NetQualitySchema::utc(Net\NetQualitySchema::string($protocol->data, 'epochStartUtc')), null, 'profile');
    foreach ($truthSlots as $slot) {
        $input = $inputs[$slot['inputId']];
        $ref = $profile->raw(Kind::Truth, $slot['requestId'], Net\NetQualitySchema::utc($slot['scheduledAtUtc']), $input, $profile->truthRows($input, $ordinals[$input->requestId]), ['policy' => 'component_truth_h60_l7_v1', 'horizonDays' => 60, 'reportingLagDays' => 7, 'epoch' => $inputCodec->pack($epoch), 'softwareRelease' => '123-aaaaaaa', 'qualityProtocol' => ['protocol' => $protocolRef->toArray()]]);
        $truthReceipts[] = ['requestId' => $ref->requestId, 'status' => 'success', 'artifact' => $ref->toArray(), 'reason' => null];
    }
    $clock->modify('2027-10-18T00:00:00Z');
    $truthRef = $sealer($company, $account, new Net\NetQualityInventoryRequest(['requestId' => Uuid::v7()->toRfc4122(), 'protocol' => $protocolRef->toArray(), 'subtype' => 'truth', 'cutoffUtc' => $protocol->data['truthCutoffUtc'], 'slots' => $truthReceipts]));
    $truthInventory = $inventories->read($company, $account, $truthRef, new Net\NetQualityBudget());
    if (!$truthInventory instanceof Net\NetQualityTruthInventory) {
        throw new \LogicException('Invalid truth inventory.');
    }
    $evaluate = new Net\EvaluateNetForecastQualityAction($reader, $protocols, $inventories, new Net\NetQualityTruthReader($reader, $inputCodec), $writer, new Net\NetQualityMetrics(), new Net\NetQualityDecision(), $codec, new Net\NetQualityReportCodec(), '123-aaaaaaa');
    $beforeBytes = $storage->bytes;
    $beforeGets = $storage->gets;
    $allocation = memory_get_usage(true);
    $started = hrtime(true);
    $budget = new Net\NetQualityBudget();
    $result = $evaluate($company, $account, $selection, $truthInventory, Uuid::v7()->toRfc4122(), $budget);
    $receipt += ['wallMs' => intdiv(hrtime(true) - $started, 1000000), 'inputRefs' => \count($inputs), 'truthRefs' => \count($truthSlots), 'resources' => $budget->summary(), 'actualGets' => $storage->gets - $beforeGets, 'actualReadBytes' => $storage->bytes - $beforeBytes, 'peakBytes' => memory_get_peak_usage(true), 'additionalPeakUpperBoundBytes' => memory_get_peak_usage(true) - $allocation, 'dataset' => $result->datasetRef->toArray(), 'report' => $result->reportRef->toArray(), 'syntheticCounts' => $result->counts, 'predictiveDecision' => 'NOT_MEASURED'];
    if ($receipt['actualReadBytes'] !== $budget->summary()['readBytes'] || 20000 !== $result->counts['overall']) {
        throw new \LogicException('Profile failed full accounting or population.');
    }
    $receipt['status'] = 'PASS';
} catch (\Throwable $error) {
    $receipt['errorType'] = $error::class;
    $receipt['error'] = $error->getMessage();
    $receipt['failureTrace'] = $error->getTraceAsString();
    $receipt['failureWallMs'] = isset($started) ? intdiv(hrtime(true) - $started, 1000000) : null;
    $receipt['failurePeakBytes'] = memory_get_peak_usage(true);
    $receipt['failureResources'] = isset($budget) ? $budget->summary() : null;
} finally {
    $receipt['cleanupAnchors'] = $db->executeStatement('DELETE FROM net_forecast_artifact WHERE company_id = ? AND marketplace_account_id = ?', [$company, $account]);
    $receipt['remainingAnchors'] = $db->fetchOne('SELECT EXISTS (SELECT 1 FROM net_forecast_artifact WHERE company_id = ? AND marketplace_account_id = ?)', [$company, $account]);
    $hashes = [];
    foreach (get_included_files() as $path) {
        if (str_contains($path, '/Ingestion/') && !str_contains($path, '/vendor/')) {
            $hashes[str_replace(\dirname(__DIR__, 3).'/', '', $path)] = hash_file('sha256', $path);
        }
    }
    ksort($hashes);
    $receipt['loadedSourceHashes'] = $hashes;
    $db->close();
    $kernel->shutdown();
    unset($writer, $reader, $publisher, $sealer, $inventories, $protocols, $evaluate, $profile, $storage);
    $receipt['storageCleanup'] = 'complete';
    fwrite(\STDOUT, json_encode($receipt, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");
}
exit('PASS' === $receipt['status'] ? 0 : 1);
