<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Infrastructure\ServiceCoverage;

use App\Ingestion\Infrastructure\ServiceCoverage\OzonFetcherCoverageClient;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageState;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Потребитель контракта service-coverage v1 (services/AGENTS.md §3):
 * эталон проходит JSON Schema и разбирается; ответ вне схемы, отказ
 * и штатные 503 сервиса — состояния, а не исключения и не пустая сводка.
 */
final class OzonFetcherCoverageClientTest extends TestCase
{
    private const string CONTRACT = '/var/www/services/_contracts/service-coverage/v1/';
    private const string BASE_URL = 'http://ozon-fetcher.test:8080/';
    private const string TOKEN = 'php-to-service-token-0123456789abcdef';

    public function testContractExampleMatchesSchemaAndParses(): void
    {
        $example = self::example();
        $schema = file_get_contents(self::CONTRACT.'schema.json');
        self::assertIsString($schema);

        $result = new Validator()->validate(json_decode($example, false, flags: \JSON_THROW_ON_ERROR), $schema);
        self::assertTrue($result->isValid(), 'example.json не проходит schema.json');

        $snapshot = OzonFetcherCoverageClient::parse($example);

        self::assertSame(ServiceCoverageState::Ok, $snapshot->state);
        self::assertSame($example, $snapshot->payload, 'сохраняется ровно разобранный ответ');
        self::assertSame(1, $snapshot->cabinetsTotal);
        self::assertSame('2026-10-25T09:00:00+00:00', $snapshot->generatedAt?->format(\DATE_ATOM));
        self::assertCount(5, $snapshot->rows);
        $daily = $snapshot->rows[1];
        self::assertSame('ozon.ads.daily', $daily->resourceType);
        self::assertSame('2026-10-24', $daily->periodKey);
        self::assertSame(1, $daily->notReady);
        self::assertNull($daily->lastSuccessAt);
        self::assertSame('2026-10-25T08:40:12+00:00', $snapshot->rows[0]->lastSuccessAt?->format(\DATE_ATOM));
    }

    public function testItAsksTheWindowWithServiceToken(): void
    {
        $method = $url = $headers = null;
        $http = new MockHttpClient(static function (string $m, string $u, array $options) use (&$method, &$url, &$headers): MockResponse {
            [$method, $url, $headers] = [$m, $u, $options['headers'] ?? []];

            return new MockResponse(self::example());
        });

        $snapshot = $this->client($http)->fetch('2026-10-23', '2026-10-25');

        self::assertSame(ServiceCoverageState::Ok, $snapshot->state);
        self::assertSame('GET', $method);
        self::assertSame('http://ozon-fetcher.test:8080/internal/coverage?from=2026-10-23&to=2026-10-25', $url);
        self::assertContains('Authorization: Bearer '.self::TOKEN, $headers);
    }

    public function testSwitchedOffOrUnconfiguredServiceIsDisabledWithoutRequest(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new \LogicException('запроса быть не должно'));

        $cases = [
            ['true', '', self::TOKEN],
            ['true', self::BASE_URL, ''],
            // Переключатель — как у ozon-fetcher-compose.sh: только `true`.
            ['false', self::BASE_URL, self::TOKEN],
            ['TRUE', self::BASE_URL, self::TOKEN],
            ['', self::BASE_URL, self::TOKEN],
        ];
        foreach ($cases as [$enabled, $baseUrl, $token]) {
            $client = new OzonFetcherCoverageClient($http, new Logger('test'), $enabled, $baseUrl, $token);
            self::assertSame(ServiceCoverageState::Disabled, $client->fetch('2026-10-23', '2026-10-25')->state);
        }
    }

    public function testServiceStatesOf503(): void
    {
        $cases = [
            'cabinets_not_ready' => ServiceCoverageState::NotReady,
            'ingestion_disabled' => ServiceCoverageState::Disabled,
            'coverage_unavailable' => ServiceCoverageState::Unavailable,
        ];
        foreach ($cases as $code => $state) {
            $body = json_encode(['error' => ['code' => $code, 'message' => 'm']], \JSON_THROW_ON_ERROR);
            $http = new MockHttpClient(new MockResponse($body, ['http_code' => 503]));

            $snapshot = $this->client($http)->fetch('2026-10-23', '2026-10-25');

            self::assertSame($state, $snapshot->state, $code);
            self::assertSame([], $snapshot->rows);
            self::assertNull($snapshot->generatedAt);
        }
    }

    public function testFailureIsLoggedWithoutTokenOrBody(): void
    {
        $log = new TestHandler();
        $http = new MockHttpClient([
            new MockResponse('{"secret-looking":"body"}', ['http_code' => 500]),
            new MockResponse('', ['error' => 'connection refused']),
        ]);
        $client = new OzonFetcherCoverageClient($http, new Logger('test', [$log]), 'true', self::BASE_URL, self::TOKEN);

        self::assertSame(ServiceCoverageState::Unavailable, $client->fetch('2026-10-23', '2026-10-25')->state);
        self::assertSame(ServiceCoverageState::Unavailable, $client->fetch('2026-10-23', '2026-10-25')->state);

        self::assertCount(2, $log->getRecords());
        self::assertSame('http_status', $log->getRecords()[0]->context['reason']);
        self::assertSame(500, $log->getRecords()[0]->context['status']);
        self::assertSame('transport', $log->getRecords()[1]->context['reason']);
        foreach ($log->getRecords() as $record) {
            $text = $record->message.json_encode($record->context, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString(self::TOKEN, $text);
            self::assertStringNotContainsString('secret-looking', $text);
        }
    }

    public function testTransportExceptionIsUnavailable(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => throw new TransportException('dns'));

        self::assertSame(ServiceCoverageState::Unavailable, $this->client($http)->fetch('2026-10-23', '2026-10-25')->state);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>}>
     */
    public static function violations(): iterable
    {
        yield 'лишнее поле' => [static fn (array $p): array => [...$p, 'extra' => 1]];
        yield 'нет поля' => [static function (array $p): array {
            unset($p['cabinets_total']);

            return $p;
        }];
        yield 'другая версия' => [static fn (array $p): array => [...$p, 'schema_version' => '2']];
        yield 'отрицательный счётчик' => [static function (array $p): array {
            $p['rows'][0]['by_status']['done'] = -1;

            return $p;
        }];
        yield 'дробный счётчик' => [static fn (array $p): array => [...$p, 'cabinets_total' => 1.5]];
        yield 'кривой период' => [static function (array $p): array {
            $p['rows'][0]['period_key'] = '2026-1-1';

            return $p;
        }];
        yield 'нет статуса' => [static function (array $p): array {
            unset($p['rows'][0]['by_status']['not_ready']);

            return $p;
        }];
        yield 'кривое время' => [static fn (array $p): array => [...$p, 'generated_at' => 'вчера']];
        yield 'несуществующая дата во времени' => [static fn (array $p): array => [...$p, 'generated_at' => '2026-02-30T09:00:00Z']];
        yield 'несуществующая дата успеха' => [static function (array $p): array {
            $p['rows'][0]['last_success_at'] = '2026-10-32T08:00:00Z';

            return $p;
        }];
        yield 'кривая дата окна' => [static fn (array $p): array => [...$p, 'from' => '2026-02-30']];
        yield 'строки не список' => [static fn (array $p): array => [...$p, 'rows' => ['a' => 1]]];
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('violations')]
    public function testResponseOutsideContractIsUnavailable(\Closure $mutate): void
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode(self::example(), true, flags: \JSON_THROW_ON_ERROR);
        $body = json_encode($mutate($payload), \JSON_THROW_ON_ERROR);

        $snapshot = $this->client(new MockHttpClient(new MockResponse($body)))->fetch('2026-10-23', '2026-10-25');

        self::assertSame(ServiceCoverageState::Unavailable, $snapshot->state);
        self::assertSame([], $snapshot->rows);
    }

    private function client(MockHttpClient $http): OzonFetcherCoverageClient
    {
        return new OzonFetcherCoverageClient($http, new Logger('test'), 'true', self::BASE_URL, self::TOKEN);
    }

    private static function example(): string
    {
        $example = file_get_contents(self::CONTRACT.'example.json');
        self::assertIsString($example);

        return $example;
    }
}
