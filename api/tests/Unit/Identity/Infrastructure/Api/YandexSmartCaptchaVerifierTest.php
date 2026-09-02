<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Api;

use App\Identity\Domain\CaptchaUnavailable;
use App\Identity\Domain\CaptchaUnavailableReason;
use App\Identity\Domain\CaptchaVerification;
use App\Identity\Infrastructure\Api\YandexSmartCaptchaVerifier;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class YandexSmartCaptchaVerifierTest extends TestCase
{
    private const string SERVER_KEY = 'test-smartcaptcha-server-key';
    private const string TOKEN = 'captcha-token';
    private const string CLIENT_IP = '203.0.113.8';

    public function testItPostsOneValidationRequestWithRequiredFormFields(): void
    {
        $method = null;
        $url = null;
        $body = null;
        $client = new MockHttpClient(static function (string $requestMethod, string $requestUrl, array $options) use (&$method, &$url, &$body): MockResponse {
            $method = $requestMethod;
            $url = $requestUrl;
            $body = $options['body'] ?? null;

            return new MockResponse('{"status":"ok"}');
        }, 'https://smartcaptcha.cloud.yandex.ru');
        $handler = new TestHandler();

        self::assertSame(CaptchaVerification::Passed, $this->verifier($client, $handler)->verify(self::TOKEN, self::CLIENT_IP));
        self::assertSame('POST', $method);
        self::assertSame('https://smartcaptcha.cloud.yandex.ru/validate', $url);
        self::assertIsString($body);
        self::assertSame(
            'secret='.self::SERVER_KEY.'&token='.self::TOKEN.'&ip='.self::CLIENT_IP,
            $body,
        );
        self::assertSame(1, $client->getRequestsCount());
        self::assertSame([], $handler->getRecords());
    }

    #[DataProvider('successfulStatuses')]
    public function testItMapsSuccessfulCaptchaStatuses(string $status, string $message, CaptchaVerification $expected): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode(['status' => $status, 'message' => $message], \JSON_THROW_ON_ERROR)));
        $handler = new TestHandler();

        self::assertSame($expected, $this->verifier($client, $handler)->verify(self::TOKEN, self::CLIENT_IP));
        self::assertSame([], $handler->getRecords());
    }

    /**
     * @return iterable<string, array{string, string, CaptchaVerification}>
     */
    public static function successfulStatuses(): iterable
    {
        yield 'passed without message' => ['ok', '', CaptchaVerification::Passed];
        yield 'passed with message' => ['ok', 'ignored response message', CaptchaVerification::Passed];
        yield 'rejected without message' => ['failed', '', CaptchaVerification::Rejected];
        yield 'rejected with message' => ['failed', 'ignored response message', CaptchaVerification::Rejected];
    }

    public function testItConvertsTransportFailureToSanitizedUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse([new TransportException('network contains '.self::TOKEN)]));

        $this->assertUnavailable($client, CaptchaUnavailableReason::Transport);
    }

    public function testItConvertsTimeoutToSanitizedUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse(['']));

        $this->assertUnavailable($client, CaptchaUnavailableReason::Transport);
    }

    public function testItConvertsNonSuccessfulHttpStatusToSanitizedUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('{"message":"response contains '.self::TOKEN.'"}', ['http_code' => 503]));

        $this->assertUnavailable($client, CaptchaUnavailableReason::HttpStatus, 503);
    }

    public function testItConvertsInvalidJsonToSanitizedUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse('{invalid json '.self::TOKEN));

        $this->assertUnavailable($client, CaptchaUnavailableReason::InvalidJson);
    }

    #[DataProvider('unexpectedStatuses')]
    public function testItConvertsUnexpectedStatusToSanitizedUnavailable(string $body): void
    {
        $client = new MockHttpClient(new MockResponse($body));

        $this->assertUnavailable($client, CaptchaUnavailableReason::UnexpectedStatus);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unexpectedStatuses(): iterable
    {
        yield 'missing status' => ['{"message":"response contains '.self::TOKEN.'"}'];
        yield 'unknown status' => ['{"status":"unknown","message":"response contains '.self::TOKEN.'"}'];
    }

    private function assertUnavailable(MockHttpClient $client, CaptchaUnavailableReason $reason, ?int $httpStatus = null): void
    {
        $handler = new TestHandler();

        try {
            $this->verifier($client, $handler)->verify(self::TOKEN, self::CLIENT_IP);
            self::fail('Unavailable SmartCaptcha validation must throw CaptchaUnavailable.');
        } catch (CaptchaUnavailable $failure) {
            self::assertSame($reason, $failure->reason);
            self::assertSame($httpStatus, $failure->httpStatus);
            self::assertStringNotContainsString(self::SERVER_KEY, $failure->getMessage());
            self::assertStringNotContainsString(self::TOKEN, $failure->getMessage());
        }

        self::assertSame(1, $client->getRequestsCount());
        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame(Level::Warning, $records[0]->level);
        self::assertSame(
            null === $httpStatus ? ['reason' => $reason->value] : ['reason' => $reason->value, 'http_status' => $httpStatus],
            $records[0]->context,
        );

        $record = json_encode(['message' => $records[0]->message, 'context' => $records[0]->context], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::SERVER_KEY, $record);
        self::assertStringNotContainsString(self::TOKEN, $record);
        self::assertStringNotContainsString(self::CLIENT_IP, $record);
        self::assertStringNotContainsString('response contains', $record);
        self::assertStringNotContainsString('network contains', $record);
    }

    private function verifier(HttpClientInterface $client, TestHandler $handler): YandexSmartCaptchaVerifier
    {
        return new YandexSmartCaptchaVerifier($client, self::SERVER_KEY, new Logger('test', [$handler]));
    }
}
