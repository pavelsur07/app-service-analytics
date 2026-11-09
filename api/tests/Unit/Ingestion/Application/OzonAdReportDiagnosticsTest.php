<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application;

use App\Ingestion\Application\Message\CheckOzonAdSkuReportMessage;
use App\Ingestion\Application\Message\OrderOzonAdSkuReportMessage;
use App\Ingestion\Application\OzonAdReportDiagnostics;
use App\Ingestion\Application\OzonAdReportFailureDetails;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class OzonAdReportDiagnosticsTest extends TestCase
{
    private const string COMPANY = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
    private const string ACCOUNT = '0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';
    private const string SECRET = 'sentinel-secret-DO-NOT-LOG';

    public function testSuccessfulPayloadAndThrownExceptionAreUnchangedAndNeverLogged(): void
    {
        $logs = new TestHandler();
        $diagnostics = new OzonAdReportDiagnostics(new Logger('ozon_report', [$logs]), 'local-hmac-key');
        $trace = $diagnostics->trace($this->order());
        $calls = 0;
        $body = '{"access_token":"'.self::SECRET.'"}';
        self::assertSame($body, $diagnostics->request($trace, 'token', static function () use ($body, &$calls): string {
            ++$calls;

            return $body;
        }));
        $failure = $this->failure('{"code":8,"message":"report is already in progress '.self::SECRET.'","token":"'.self::SECRET.'"}', ['Retry-After: 3600', 'X-Request-Id: '.self::SECRET]);
        try {
            $diagnostics->request($trace, 'order', static function () use ($failure, &$calls): string {
                ++$calls;
                throw $failure;
            });
            self::fail('Must rethrow original exception.');
        } catch (HttpExceptionInterface $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame(2, $calls);
        $records = $logs->getRecords();
        self::assertCount(4, $records);
        self::assertSame('request_returned', $records[1]->context['event']);
        self::assertArrayNotHasKey('http_status', $records[1]->context);
        $context = $records[3]->context;
        self::assertSame('concurrent_report', $context['reason_hint']);
        self::assertSame(8, $context['provider_code']);
        self::assertSame(429, $context['http_status']);
        self::assertSame(3600, $context['retry_after_seconds']);
        self::assertSame(hash_hmac('sha256', "ozon-report-request:v1\0".self::COMPANY."\0".self::SECRET, 'local-hmac-key'), $context['provider_request_ref']);
        foreach ($records as $record) {
            self::assertSame('ozon_report_diagnostic', $record->message);
            self::assertSame(self::COMPANY, $record->context['company_id']);
            self::assertSame(self::ACCOUNT, $record->context['marketplace_account_id']);
            self::assertSame($trace->invocationId, $record->context['invocation_id']);
            self::assertStringNotContainsString(self::SECRET, (string) json_encode($record));
            self::assertStringNotContainsString('mock.invalid', (string) json_encode($record));
        }
        // Диагностическое чтение не уничтожает тело исключения.
        self::assertStringContainsString(self::SECRET, $failure->getResponse()->getContent(false));
    }

    /** @return iterable<string, array{list<string>, string, ?int}> */
    public static function retryAfterCases(): iterable
    {
        yield 'missing' => [[], 'missing', null];
        yield 'zero' => [['Retry-After: 0'], 'seconds', 0];
        yield 'seconds' => [['Retry-After: 3600'], 'seconds', 3600];
        yield 'date' => [['Retry-After: Mon, 09 Nov 2026 00:01:00 GMT'], 'http_date', 60];
        yield 'past date' => [['Retry-After: Sun, 08 Nov 2026 23:59:00 GMT'], 'http_date', 0];
        yield 'not a date' => [['Retry-After: tomorrow'], 'invalid', null];
        yield 'wrong weekday' => [['Retry-After: Tue, 09 Nov 2026 00:01:00 GMT'], 'invalid', null];
        yield 'negative' => [['Retry-After: -10'], 'invalid', null];
        yield 'overflow' => [['Retry-After: 99999999999999999999999999'], 'invalid', null];
        yield 'duplicate' => [['Retry-After: 10', 'Retry-After: 20'], 'invalid', null];
    }

    /** @param list<string> $headers */
    #[DataProvider('retryAfterCases')]
    public function testRetryAfterIsDiagnosticOnly(array $headers, string $kind, ?int $seconds): void
    {
        $details = OzonAdReportFailureDetails::describe($this->failure('{}', $headers), 1_794_182_400, self::COMPANY, 'key');
        self::assertSame($kind, $details['retry_after_kind']);
        self::assertSame($seconds, $details['retry_after_seconds']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function bodies(): iterable
    {
        yield 'generic 429 is unknown' => ['{"message":"Too many requests"}', 'json', 'unknown'];
        yield 'explicit daily hint' => ['{"error":"daily report quota exceeded"}', 'json', 'daily_limit'];
        yield 'explicit short window' => ['{"error":{"message":"limit exceeded per minute"}}', 'json', 'short_window_limit'];
        yield 'conflicting hints' => ['{"message":"daily limit","error":"concurrent report limit"}', 'json', 'unknown'];
        yield 'unrelated field ignored' => ['{"token":"daily limit"}', 'json', 'unknown'];
        yield 'arbitrary text' => [self::SECRET, 'invalid_json', 'unknown'];
        yield 'invalid utf8' => ["{\"error\":\"\xff\"}", 'invalid_json', 'unknown'];
        yield 'oversized' => [str_repeat('x', 16385), 'oversized', 'unknown'];
        yield 'nested too deeply' => [str_repeat('[', 20).'0'.str_repeat(']', 20), 'invalid_json', 'unknown'];
        yield 'scalar' => ['"daily limit"', 'json', 'unknown'];
    }

    #[DataProvider('bodies')]
    public function testBodiesHaveBoundedNonSecretOutput(string $body, string $diagnostic, string $hint): void
    {
        $failure = $this->failure($body);
        $details = OzonAdReportFailureDetails::describe($failure, 0, self::COMPANY, 'key');
        self::assertSame($diagnostic, $details['body_diagnostic']);
        self::assertSame($hint, $details['reason_hint']);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($details));
        self::assertLessThan(512, \strlen((string) json_encode($details)));
        self::assertSame($body, $failure->getResponse()->getContent(false));
    }

    public function testUnavailableResponseDoesNotReplaceOriginalFailure(): void
    {
        $response = self::createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(429);
        $response->method('getHeaders')->willThrowException(new \RuntimeException(self::SECRET));
        $failure = new class($response) extends \RuntimeException implements HttpExceptionInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
                parent::__construct('secret exception');
            }

            public function getResponse(): ResponseInterface
            {
                return $this->response;
            }
        };
        $details = OzonAdReportFailureDetails::describe($failure, 0, self::COMPANY, 'key');
        self::assertSame(429, $details['http_status']);
        self::assertSame('unknown', $details['reason_hint']);
        self::assertSame('unavailable', $details['body_diagnostic']);
        self::assertStringNotContainsString('secret', (string) json_encode($details));
    }

    public function testRequestReferenceIsTenantScopedAndUnsupportedBodiesAreNotClassified(): void
    {
        $failure = $this->failure('{"message":"daily limit '.self::SECRET.'"}', ['X-Request-Id: '.self::SECRET, 'Content-Type: text/html']);
        $one = OzonAdReportFailureDetails::describe($failure, 0, self::COMPANY, 'key');
        $two = OzonAdReportFailureDetails::describe($failure, 0, self::ACCOUNT, 'key');
        self::assertNotNull($one['provider_request_ref']);
        self::assertNotSame($one['provider_request_ref'], $two['provider_request_ref']);
        self::assertSame('unsupported_content_type', $one['body_diagnostic']);
        self::assertSame('unknown', $one['reason_hint']);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode([$one, $two]));
        $duplicate = OzonAdReportFailureDetails::describe($this->failure('{}', ['X-Request-Id: one', 'X-Request-Id: two']), 0, self::COMPANY, 'key');
        self::assertNull($duplicate['provider_request_ref']);
    }

    public function testLoggerFailureDoesNotAffectCallsOrExceptions(): void
    {
        $logger = self::createStub(LoggerInterface::class);
        $logger->method('info')->willThrowException(new \RuntimeException('log unavailable'));
        $diagnostics = new OzonAdReportDiagnostics($logger, 'key');
        $trace = $diagnostics->trace($this->order());
        self::assertSame('payload', $diagnostics->request($trace, 'download', static fn (): string => 'payload'));
        $failure = new \RuntimeException(self::SECRET);
        try {
            $diagnostics->request($trace, 'check', static fn (): string => throw $failure);
            self::fail('Must preserve failure.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testLogicalReferenceIsScopedAndStableAcrossRetriesButDeliveriesDiffer(): void
    {
        $diagnostics = new OzonAdReportDiagnostics(new Logger('test'), 'key');
        $message = $this->order();
        $first = $diagnostics->trace($message);
        $restored = unserialize(serialize($message));
        self::assertInstanceOf(OrderOzonAdSkuReportMessage::class, $restored);
        $again = $diagnostics->trace($restored);
        self::assertSame($first->context()['order_ref'], $again->context()['order_ref']);
        self::assertNotSame($first->invocationId, $again->invocationId);
        $retry = new OrderOzonAdSkuReportMessage(self::COMPANY, self::ACCOUNT, '2026-11-01', '2026-11-07', ['2', '1'], 2, '2026-11-09T00:00:00Z');
        self::assertSame($first->context()['order_ref'], $diagnostics->trace($retry)->context()['order_ref']);
        $other = new OrderOzonAdSkuReportMessage(self::ACCOUNT, self::ACCOUNT, '2026-11-01', '2026-11-07', ['1', '2']);
        self::assertNotSame($first->context()['order_ref'], $diagnostics->trace($other)->context()['order_ref']);
    }

    public function testUnknownStateAndIdentifierCannotEchoSecrets(): void
    {
        $logs = new TestHandler();
        $diagnostics = new OzonAdReportDiagnostics(new Logger('test', [$logs]), 'key');
        $message = new CheckOzonAdSkuReportMessage(self::COMPANY, self::ACCOUNT, '2026-11-01', self::SECRET, 1);
        $diagnostics->event($diagnostics->trace($message), 'state', reportUuid: self::SECRET, state: self::SECRET);
        self::assertNull($logs->getRecords()[0]->context['report_uuid']);
        self::assertSame('unknown', $logs->getRecords()[0]->context['state']);
        self::assertStringNotContainsString(self::SECRET, (string) json_encode($logs->getRecords()));
    }

    private function order(): OrderOzonAdSkuReportMessage
    {
        return new OrderOzonAdSkuReportMessage(self::COMPANY, self::ACCOUNT, '2026-11-01', '2026-11-07', ['1', '2']);
    }

    /** @param list<string> $headers */
    private function failure(string $body, array $headers = []): HttpExceptionInterface
    {
        $http = new MockHttpClient(new MockResponse($body, ['http_code' => 429, 'response_headers' => ['Content-Type: application/json', ...$headers]]));
        try {
            $http->request('POST', 'https://mock.invalid/report?secret='.self::SECRET)->getContent();
        } catch (HttpExceptionInterface $failure) {
            return $failure;
        }
        throw new \LogicException('Expected mock HTTP failure.');
    }
}
