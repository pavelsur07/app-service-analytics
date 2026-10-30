<?php

declare(strict_types=1);

// Mounted read-only into the preregistered image. No marketplace calls or gate writes.
use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\NetForwardArtifactKind as Kind;
use App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository;
use App\Ingestion\Infrastructure\Storage\S3NetForwardArtifactStorage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

function netQualitySameRef(mixed $value, Net\NetQualityArtifactRef $expected): bool
{
    return is_array($value) && Net\NetQualityArtifactRef::fromArray($value)->toArray() === $expected->toArray();
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

try {
    require 'vendor/autoload.php';
    (new Symfony\Component\Dotenv\Dotenv())->bootEnv('.env');
    $body = stream_get_contents(STDIN, 1048577);
    if (false === $body || strlen($body) > 1048576) {
        throw new InvalidArgumentException('Bounded request required.');
    }
    $input = json_decode($body, true, 40, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_array($input['config'] ?? null) || !is_bool($input['recoverOnly'] ?? null)) {
        throw new InvalidArgumentException('Invalid bridge request.');
    }
    $config = $input['config'];
    $requested = new Net\NetQualityProtocol($config['data']);
    $ref = Net\NetQualityArtifactRef::fromArray($config['protocol']);
    if (getenv('APP_SOURCE_COMMIT') !== $requested->data['codeCommit'] || !str_ends_with(getenv('APP_VERSION') ?: '', substr($requested->data['codeCommit'], 0, 7)) || !hash_equals($config['epochHash'], hash('sha256', getenv('NET_FORECAST_CAPTURE_EPOCHS_JSON') ?: '')) || '' !== (getenv('NET_FORECAST_PUBLISHED_MODEL_VERSION') ?: '')) {
        throw new DomainException('Producer, epoch, or publication mismatch.');
    }
    $epoch = (new App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs(getenv('NET_FORECAST_CAPTURE_EPOCHS_JSON') ?: '[]'))->at(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    if (null === $epoch || $epoch->id !== $requested->data['epochId'] || $epoch->start->format('Y-m-d\TH:i:s.u\Z') !== $requested->data['epochStartUtc']) {
        throw new DomainException('Registered epoch unavailable.');
    }
    $kernel = new App\Kernel('prod', false);
    $kernel->boot();
    $db = $kernel->getContainer()->get('doctrine')->getConnection();
    $env = static fn (string $key): string => $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    $http = Symfony\Component\HttpClient\HttpClient::create(['timeout' => 2, 'max_duration' => 2, 'headers' => ['Accept-Encoding' => 'identity']]);
    $s3 = new AsyncAws\S3\S3Client(['endpoint' => $env('RAW_STORAGE_ENDPOINT'), 'region' => $env('RAW_STORAGE_REGION'), 'accessKeyId' => $env('RAW_STORAGE_ACCESS_KEY'), 'accessKeySecret' => $env('RAW_STORAGE_SECRET_KEY'), 'pathStyleEndpoint' => 'true'], httpClient: $http, logger: null);
    $repository = new DbalNetForecastArtifactRepository($db);
    $codec = new Net\NetForwardManifestCodec();
    $reader = new Net\NetForwardArtifactReader($db, $repository, new S3NetForwardArtifactStorage($s3, $env('RAW_STORAGE_BUCKET')), $codec);
    $company = $requested->companyId;
    $account = $requested->accountId;
    $operatorBudget = new Net\NetQualityBudget();
    $context = (new Net\NetQualityProtocolReader($reader))->context($company, $account, $ref, $operatorBudget);
    if ($codec->encode($requested->data) !== $codec->encode($context->protocol->data)) {
        throw new DomainException('Local configuration differs from sealed protocol.');
    }
    $protocol = $context->protocol;
    $operation = $input['operation'] ?? null;
    if ('audit' === $operation) {
        echo '{"status":"verified","numericRolloutAllowed":false}'.PHP_EOL;
        exit(0);
    }
    $id = Net\NetQualitySchema::uuid($input['requestId'] ?? '', true);
    $operations = $config['operations'];
    if (!is_array($operations)) {
        throw new InvalidArgumentException('Explicit operation IDs required.');
    }
    foreach (['captureInventory', 'selection', 'truthInventory', 'evaluation'] as $name) {
        Net\NetQualitySchema::uuid($operations[$name] ?? '', true);
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $slot = null;
    if (in_array($operation, ['capture', 'truth'], true)) {
        foreach ('capture' === $operation ? $protocol->slots : $protocol->truthSlots as $candidate) {
            if ($candidate['requestId'] === $id) {
                $slot = $candidate;
            }
        }
        if (null === $slot || $now < Net\NetQualitySchema::utc($slot['scheduledAtUtc'])) {
            throw new DomainException('No due registered slot.');
        }
        $until = Net\NetQualitySchema::utc($slot['scheduledAtUtc'])->modify('+15 minutes');
    } else {
        $schedule = ['captureInventory' => ['captureToUtc', '+1 minute'], 'selection' => ['captureToUtc', '+2 minutes'], 'truthInventory' => ['truthCutoffUtc', '+1 minute'], 'evaluation' => ['truthCutoffUtc', '+3 minutes']];
        if (!is_string($operation) || !isset($schedule[$operation]) || $id !== $operations[$operation]) {
            throw new DomainException('Unregistered operation.');
        }
        [$cutoffKey, $delay] = $schedule[$operation];
        if ($now < Net\NetQualitySchema::utc($protocol->data[$cutoffKey])->modify($delay)) {
            throw new DomainException('Control cutoff has not elapsed.');
        }
        $until = Net\NetQualitySchema::utc($protocol->data['truthCutoffUtc']);
        if (in_array($operation, ['truthInventory', 'evaluation'], true)) {
            $until = $until->modify('+1 day');
        }
    }
    $recoverOnly = $input['recoverOnly'] || $now >= $until;
    $kind = match ($operation) {
        'capture' => Kind::Input, 'truth' => Kind::Truth,
        'captureInventory', 'truthInventory' => Kind::QualityInventory,
        'selection' => Kind::QualitySelection, 'evaluation' => Kind::QualityReport,
    };
    $find = static function (Kind $kind, string $requestId) use ($repository, $reader, $company, $account, $operatorBudget): ?Net\VerifiedNetForwardArtifact {
        $publication = $repository->find($company, $account, $kind, $requestId);

        return null === $publication ? null : $reader->read($company, $account, $kind, $requestId, $publication->root->sha256, deadline: $operatorBudget->deadline, budget: $operatorBudget);
    };
    $reference = static fn (Net\VerifiedNetForwardArtifact $artifact): array => (new Net\NetQualityArtifactRef($artifact->publication->root->kind, $artifact->publication->root->requestId, $artifact->publication->root->sha256))->toArray();
    $existing = $find($kind, $id);
    if (null !== $existing) {
        if (null !== $slot && $existing->readAt < Net\NetQualitySchema::utc($slot['scheduledAtUtc'])) {
            throw new DomainException('Existing artifact precedes registered slot.');
        }
        if ('capture' === $operation) {
            $expected = new Net\NetForwardCaptureRequest($id, $protocol->planId, $protocol->from, $protocol->to, qualityProtocolId: $ref->requestId, qualityProtocolHash: $ref->sha256);
            if ($expected->hash() !== $existing->publication->requestHash) {
                throw new DomainException('Existing capture request conflicts.');
            }
        } elseif ('truth' === $operation) {
            if (($existing->manifest['parent']['requestId'] ?? null) !== $slot['inputId'] || !netQualitySameRef($existing->manifest['metadata']['qualityProtocol']['protocol'] ?? null, $ref)) {
                throw new DomainException('Existing truth parent conflicts.');
            }
        } elseif (!netQualitySameRef($existing->manifest['metadata']['protocol'] ?? null, $ref) && Kind::QualityInventory !== $kind) {
            throw new DomainException('Existing quality protocol conflicts.');
        }
        if ('selection' === $operation && ($existing->manifest['parent']['requestId'] ?? null) !== $operations['captureInventory']) {
            throw new DomainException('Existing selection inventory conflicts.');
        }
        if (Kind::QualityInventory === $kind) {
            $inventory = (new Net\NetQualityInventoryReader($reader))->read($company, $account, Net\NetQualityArtifactRef::fromArray($reference($existing)), $operatorBudget);
            if ($inventory->protocolRef->toArray() !== $ref->toArray() || ('captureInventory' === $operation) !== ($inventory instanceof Net\NetQualityCaptureInventory)) {
                throw new DomainException('Existing inventory conflicts.');
            }
        }
        $result = ['status' => 'sealed', 'artifact' => $reference($existing), 'recovered' => true, 'numericRolloutAllowed' => false];
        if ('evaluation' === $operation) {
            [, $report] = (new Net\NetQualityArtifactPayloadReader($reader))->read($company, $account, Net\NetQualityArtifactRef::fromArray($reference($existing)), 'quality_report', $operatorBudget);
            if (($report['selection']['requestId'] ?? null) !== $operations['selection'] || ($report['truthInventory']['requestId'] ?? null) !== $operations['truthInventory']) {
                throw new DomainException('Existing report dependencies conflict.');
            }
            $verifiedReport = (new Net\NetQualityReportCodec())->result($report, Net\NetQualityArtifactRef::fromArray($reference($existing)), Net\NetQualityArtifactRef::fromArray($report['dataset']));
            $result['decision'] = $verifiedReport->decision;
            $result['counts'] = $verifiedReport->counts;
        }
    } elseif ($recoverOnly) {
        $result = ['status' => 'missing', 'reason' => 'registered_operator_window_missed', 'numericRolloutAllowed' => false];
    } else {
        $app = new Application($kernel);
        $app->setAutoExit(false);
        $args = ['--no-interaction' => true];
        $file = null;
        if ('capture' === $operation) {
            $args += ['command' => 'app:sales-planning:capture-forward', 'company' => $company, 'account' => $account, 'plan' => $protocol->planId, 'request' => $id, 'from' => $protocol->from, 'to' => $protocol->to, '--quality-protocol' => $ref->requestId, '--quality-protocol-hash' => $ref->sha256];
        } elseif ('truth' === $operation) {
            $parent = $find(Kind::Input, $slot['inputId']);
            if (null === $parent || !netQualitySameRef($parent->manifest['metadata']['qualityProtocol']['protocol'] ?? null, $ref)) {
                throw new DomainException('Registered input unavailable.');
            }
            $args += ['command' => 'app:ingestion:forward-artifact', 'mode' => 'truth', 'company' => $company, 'account' => $account, 'input' => $slot['inputId'], 'hash' => $parent->publication->root->sha256, 'truth-request' => $id];
        } else {
            $args += ['command' => 'app:ingestion:net-forecast-quality', '--company' => $company, '--account' => $account, '--operation' => $id];
            $requireRef = static function (Kind $kind, string $requestId) use ($find, $reference): array {
                $artifact = $find($kind, $requestId) ?? throw new DomainException('Required prior artifact unavailable.');

                return $reference($artifact);
            };
            if ('evaluation' === $operation) {
                $selection = $requireRef(Kind::QualitySelection, $operations['selection']);
                $truths = $requireRef(Kind::QualityInventory, $operations['truthInventory']);
                $args += ['mode' => 'evaluate', '--selection' => $selection['requestId'], '--selection-hash' => $selection['artifactHash'], '--truth-inventory' => $truths['requestId'], '--truth-inventory-hash' => $truths['artifactHash']];
            } else {
                if ('selection' === $operation) {
                    $document = ['requestId' => $id, 'protocol' => $ref->toArray(), 'inventory' => $requireRef(Kind::QualityInventory, $operations['captureInventory'])];
                    $args['mode'] = 'select';
                } else {
                    $capture = 'captureInventory' === $operation;
                    $cutoff = $protocol->data[$capture ? 'captureToUtc' : 'truthCutoffUtc'];
                    $slots = [];
                    foreach ($capture ? $protocol->slots : $protocol->truthSlots as $registered) {
                        $artifact = $find($capture ? Kind::Input : Kind::Truth, $registered['requestId']);
                        $late = null !== $artifact && $artifact->readAt > Net\NetQualitySchema::utc($cutoff);
                        $slots[] = ['requestId' => $registered['requestId'], 'status' => null === $artifact ? 'missing' : ($late ? 'late' : 'success'), 'artifact' => null === $artifact ? null : $reference($artifact), 'reason' => null === $artifact ? 'no_publication_at_cutoff' : ($late ? 'published_after_cutoff' : null)];
                    }
                    $document = ['requestId' => $id, 'protocol' => $ref->toArray(), 'subtype' => $capture ? 'capture' : 'truth', 'cutoffUtc' => $cutoff, 'slots' => $slots];
                    $args['mode'] = 'seal-inventory';
                }
                $bytes = json_encode($document, JSON_THROW_ON_ERROR);
                $file = tempnam(sys_get_temp_dir(), 'quality-operator-');
                if (false === $file || !chmod($file, 0600) || strlen($bytes) !== file_put_contents($file, $bytes)) {
                    throw new RuntimeException('Private request file unavailable.');
                }
                $args += ['--request-file' => $file, '--request-hash' => hash('sha256', $bytes)];
            }
        }
        $output = new BufferedOutput();
        try {
            $exit = $app->run(new ArrayInput($args), $output);
            if (0 !== $exit) {
                throw new RuntimeException('Existing application command failed.');
            }
            $payload = json_decode(trim($output->fetch()), true, 40, JSON_THROW_ON_ERROR);
        } finally {
            if (null !== $file) {
                unlink($file);
            }
        }
        $returned = match ($operation) {
            'capture', 'truth' => new Net\NetQualityArtifactRef($kind, $payload['requestId'], $payload['artifactHash']),
            'evaluation' => Net\NetQualityArtifactRef::fromArray($payload['report']),
            default => Net\NetQualityArtifactRef::fromArray($payload['artifact']),
        };
        $publication = $repository->find($company, $account, $kind, $id);
        if ($returned->kind !== $kind || $returned->requestId !== $id || null === $publication || $publication->root->sha256 !== $returned->sha256) {
            throw new RuntimeException('Verified application result has no matching publication.');
        }
        $result = ['status' => 'sealed', 'artifact' => $returned->toArray(), 'recovered' => false, 'numericRolloutAllowed' => false];
        if ('evaluation' === $operation) {
            $result['decision'] = $payload['decision'] ?? throw new UnexpectedValueException('Quality decision missing.');
            $result['counts'] = $payload['counts'];
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    // Never expose SQL, storage keys, scoped identities or financial bodies.
    fwrite(STDERR, get_class($error).PHP_EOL);
    echo '{"status":"failed","numericRolloutAllowed":false}'.PHP_EOL;
    exit(1);
}
