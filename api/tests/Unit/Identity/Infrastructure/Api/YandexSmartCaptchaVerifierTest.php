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
    private const string SELLER_ORIGIN = 'https://app.conwix.test';
    private const string SELLER_HOST = 'app.conwix.test';

    public function testItPostsOneValidationRequestWithRequiredFormFields(): void
    {
        $method = null;
        $url = null;
        $body = null;
        $maxRedirects = null;
        $client = new MockHttpClient(static function (string $requestMethod, string $requestUrl, array $options) use (&$method, &$url, &$body, &$maxRedirects): MockResponse {
            $method = $requestMethod;
            $url = $requestUrl;
            $body = $options['body'] ?? null;
            $maxRedirects = $options['max_redirects'] ?? null;

            return new MockResponse('{"status":"ok","host":"'.self::SELLER_HOST.'"}');
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
        self::assertSame(0, $maxRedirects);
        self::assertSame(1, $client->getRequestsCount());
        self::assertSame([], $handler->getRecords());
    }

    #[DataProvider('successfulStatuses')]
    public function testItMapsSuccessfulCaptchaStatuses(string $status, string $message, CaptchaVerification $expected): void
    {
        $payload = ['status' => $status, 'message' => $message];
        if ('ok' === $status) {
            $payload['host'] = self::SELLER_HOST;
        }

        $client = new MockHttpClient(new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR)));
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

    #[DataProvider('matchingSellerAuthorities')]
    public function testItAcceptsStatusOkForExactSellerAuthority(string $sellerOrigin, string $responseHost): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'status' => 'ok',
            'host' => $responseHost,
        ], \JSON_THROW_ON_ERROR)));
        $handler = new TestHandler();

        self::assertSame(
            CaptchaVerification::Passed,
            $this->verifier($client, $handler, $sellerOrigin)->verify(self::TOKEN, self::CLIENT_IP),
        );
        self::assertSame([], $handler->getRecords());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function matchingSellerAuthorities(): iterable
    {
        yield 'host without explicit port' => ['https://app.conwix.test', 'app.conwix.test'];
        yield 'host with explicit port' => ['http://localhost:5173', 'localhost:5173'];
    }

    #[DataProvider('unexpectedSuccessHosts')]
    public function testItConvertsUnexpectedSuccessHostToSanitizedUnavailable(string $body, string $externalHost): void
    {
        $client = new MockHttpClient(new MockResponse($body));

        $this->assertUnavailable(
            $client,
            CaptchaUnavailableReason::UnexpectedHost,
            sellerOrigin: self::SELLER_ORIGIN,
            sensitiveValues: '' === $externalHost ? [] : [$externalHost],
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unexpectedSuccessHosts(): iterable
    {
        yield 'missing host' => ['{"status":"ok"}', ''];
        yield 'empty host' => ['{"status":"ok","host":""}', ''];
        yield 'non-string host' => ['{"status":"ok","host":42}', ''];
        yield 'different host' => ['{"status":"ok","host":"attacker.example"}', 'attacker.example'];
        yield 'unexpected port' => ['{"status":"ok","host":"app.conwix.test:8443"}', 'app.conwix.test:8443'];
    }

    #[DataProvider('invalidSellerOrigins')]
    public function testItRejectsInvalidSellerOriginConfiguration(string $sellerOrigin): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('SELLER_APP_ORIGIN must be an absolute HTTP(S) origin.');

        $this->verifier(new MockHttpClient(), new TestHandler(), $sellerOrigin);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidSellerOrigins(): iterable
    {
        yield 'missing scheme' => ['app.conwix.test'];
        yield 'unsupported scheme' => ['ftp://app.conwix.test'];
        yield 'username in authority' => ['https://user@app.conwix.test'];
        yield 'credentials in authority' => ['https://user:password@app.conwix.test'];
        yield 'non-root path' => ['https://app.conwix.test/register'];
        yield 'query' => ['https://app.conwix.test?source=registration'];
        yield 'fragment' => ['https://app.conwix.test#registration'];
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

    public function testItDoesNotFollowRedirectAndConvertsItToSanitizedUnavailable(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '',
            [
                'http_code' => 307,
                'response_headers' => ['location: https://redirect.example/validate'],
            ],
        ));

        $this->assertUnavailable(
            $client,
            CaptchaUnavailableReason::HttpStatus,
            307,
            sensitiveValues: ['redirect.example'],
        );
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

    /**
     * @param list<string> $sensitiveValues
     */
    private function assertUnavailable(
        MockHttpClient $client,
        CaptchaUnavailableReason $reason,
        ?int $httpStatus = null,
        string $sellerOrigin = self::SELLER_ORIGIN,
        array $sensitiveValues = [],
    ): void {
        $handler = new TestHandler();

        try {
            $this->verifier($client, $handler, $sellerOrigin)->verify(self::TOKEN, self::CLIENT_IP);
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
        foreach ($sensitiveValues as $sensitiveValue) {
            self::assertStringNotContainsString($sensitiveValue, $record);
        }
    }

    private function verifier(
        HttpClientInterface $client,
        TestHandler $handler,
        string $sellerOrigin = self::SELLER_ORIGIN,
    ): YandexSmartCaptchaVerifier {
        return new YandexSmartCaptchaVerifier(
            $client,
            self::SERVER_KEY,
            new Logger('test', [$handler]),
            $sellerOrigin,
        );
    }
}
