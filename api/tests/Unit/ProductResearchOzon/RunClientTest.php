<?php

declare(strict_types=1);

namespace App\Tests\Unit\ProductResearchOzon;

use App\ProductResearchOzon\Domain\RunSyncFailure;
use App\ProductResearchOzon\Domain\ValueObject\RunDelivery;
use App\ProductResearchOzon\Infrastructure\Api\HttpRunSource;
use App\ProductResearchOzon\Infrastructure\Config\CatalogContext;
use App\Tests\Support\Builder\ResearchRunBuilder;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

final class RunClientTest extends TestCase
{
    public function testV1AdmissionAndV2ProgressKeepCorePayloadCompatible(): void
    {
        $run = ResearchRunBuilder::aRun()->build();
        $company = $run->companyId()->toRfc4122();
        $id = $run->id()->toRfc4122();
        $sourceId = $run->sourceId()->toRfc4122();
        $schemaText = file_get_contents('/var/www/services/_contracts/product-research-ozon/v1/schema.json');
        self::assertIsString($schemaText);
        $schema = json_decode($schemaText, flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $schema);
        $schema->oneOf = [(object) ['$ref' => '#/$defs/run_request']];
        self::assertTrue(new Validator()->validate(json_decode($run->payload(), flags: \JSON_THROW_ON_ERROR), $schema)->isValid());
        $receipt = ['schema_version' => '1', 'company_id' => $company, 'run_id' => $id, 'status' => 'queued', 'revision' => 1];
        $progress = [...$receipt, 'months' => [['period' => ['month' => '2026-01', 'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'days' => 31, 'partial' => false], 'status' => 'queued', 'snapshot_id' => null, 'row_count' => null, 'error_code' => null]]];
        $post = $this->response($receipt, $sourceId, 202);
        $v2 = [...$progress, 'schema_version' => '2', 'months' => [array_merge($progress['months'][0], ['origin' => null, 'collected_at' => null, 'used_at' => null])]];
        $v2Schema = json_decode((string) file_get_contents('/var/www/services/_contracts/product-research-ozon/v2/schema.json'), flags: \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $v2Schema);
        self::assertTrue(new Validator()->validate(json_decode(json_encode($v2, \JSON_THROW_ON_ERROR), flags: \JSON_THROW_ON_ERROR), $v2Schema)->isValid());
        $get = $this->response($v2, $sourceId);
        $client = new HttpRunSource(new MockHttpClient([$post, $get]), str_repeat('t', 40), new CatalogContext($sourceId));
        $client->submit($company, new RunDelivery($company, $id, $run->projectId()->toRfc4122(), $sourceId, $run->payload(), hash('sha256', $run->payload()), 1));
        self::assertSame($progress, $client->progress($company, $id, $sourceId)->payload());
        self::assertSame('POST', $post->getRequestMethod());
        self::assertStringContainsString('/internal/v2/', $get->getRequestUrl());
        self::assertSame($run->payload(), $post->getRequestOptions()['body']);
        self::assertSame(0, $get->getRequestOptions()['max_redirects']);
    }

    public function testUntrustedProgressAndErrorBodiesRemainSafe(): void
    {
        $company = Uuid::v7()->toRfc4122();
        $run = Uuid::v7()->toRfc4122();
        $source = Uuid::v7()->toRfc4122();
        $valid = ['schema_version' => '1', 'company_id' => $company, 'run_id' => $run, 'status' => 'queued', 'revision' => 1, 'months' => []];
        foreach ([$this->response($valid, $source), $this->response([...$valid, 'extra' => 1], $source), $this->response($valid, Uuid::v7()->toRfc4122()), new MockResponse(str_repeat('private', 10000), ['http_code' => 200, 'response_headers' => ['X-Research-Source-Id: '.$source]]), new MockResponse('private-body', ['http_code' => 500])] as $response) {
            try {
                new HttpRunSource(new MockHttpClient($response), str_repeat('private-token-', 3), new CatalogContext($source))->progress($company, $run, $source);
                self::fail('invalid progress accepted');
            } catch (RunSyncFailure $error) {
                self::assertStringNotContainsString('private', $error->getMessage());
            }
        }
    }

    public function testReadyProvenanceIsValidatedAndNormalised(): void
    {
        $run = ResearchRunBuilder::aRun()->build();
        $company = $run->companyId()->toRfc4122();
        $id = $run->id()->toRfc4122();
        $source = $run->sourceId()->toRfc4122();
        $month = ['period' => ['month' => '2026-01', 'date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'days' => 31, 'partial' => false], 'status' => 'ready', 'snapshot_id' => Uuid::v7()->toRfc4122(), 'row_count' => 0, 'error_code' => null, 'origin' => 'cache', 'collected_at' => '2026-04-15T12:00:00+03:00', 'used_at' => '2026-04-15T09:01:00Z'];
        $body = ['schema_version' => '2', 'company_id' => $company, 'run_id' => $id, 'status' => 'ready', 'revision' => 9, 'months' => [$month]];
        $make = fn (array $payload): HttpRunSource => new HttpRunSource(new MockHttpClient($this->response($payload, $source)), str_repeat('t', 40), new CatalogContext($source));
        $result = $make($body)->progress($company, $id, $source);
        self::assertSame('2026-04-15T09:00:00.000000+00:00', $result->months[0]->provenance?->payload()['collected_at']);
        foreach ([['origin' => 'foreign'], ['collected_at' => '2026-02-31T00:00:00Z'], ['origin' => null], ['used_at' => '2026-04-01T00:00:00Z'], ['used_at' => null], ['origin' => 'api', 'collected_at' => 1]] as $invalid) {
            try {
                $make([...$body, 'months' => [[...$month, ...$invalid]]])->progress($company, $id, $source);
                self::fail('untrusted metadata accepted');
            } catch (RunSyncFailure $error) {
                self::assertSame('invalid_run_response', $error->reason);
            }
        }
    }

    /** @param array<string, mixed> $body */
    private function response(array $body, string $source, int $code = 200): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['http_code' => $code, 'response_headers' => ['X-Research-Source-Id: '.$source]]);
    }
}
