<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application;

use App\Ingestion\Application\OzonAccountBrokenLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Инцидент, ради которого класс появился: отказ авторизации терялся
 * целиком — молчаливый catch, без следа ни в очереди, ни в трекере,
 * ни в журнале. Эти тесты проверяют ровно то, что тогда отсутствовало:
 * область, код ответа, тело и привязку к компании/подключению без
 * доступа к площадке — и то, что секрет в запись не попадает никаким путём.
 */
final class OzonAccountBrokenLoggerTest extends TestCase
{
    public function testLogsScopeStatusBodyAndTenantContextAtWarningLevel(): void
    {
        [$logger, $handler] = $this->logger();
        $failure = $this->httpFailure(401, '{"code":16,"message":"unauthenticated"}');

        $logger->log('company-1', 'account-1', 'sales', $failure, 'irrelevant-key');

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        $record = $records[0];

        self::assertSame(Level::Warning, $record->level);
        self::assertSame('sales', $record->context['scope']);
        self::assertSame(401, $record->context['status_code']);
        self::assertSame('{"code":16,"message":"unauthenticated"}', $record->context['response_body']);
        self::assertSame('company-1', $record->context['company_id']);
        self::assertSame('account-1', $record->context['marketplace_account_id']);
    }

    public function testApiKeyIsRedactedFromTheResponseBody(): void
    {
        [$logger, $handler] = $this->logger();
        // Тело гипотетическое — Ozon не отдаёт api_key в ответе, но запись
        // не должна раскрыть ключ даже в этом случае: тело эхом отражает
        // присланные параметры (отладочный дамп, сообщение об ошибке).
        $failure = $this->httpFailure(403, '{"code":16,"message":"api_key live-secret-42 is invalid"}');

        $logger->log('company-1', 'account-1', 'expenses', $failure, 'live-secret-42');

        $record = $handler->getRecords()[0];
        self::assertStringNotContainsString('live-secret-42', $this->responseBodyOf($record));
        self::assertStringContainsString('[redacted]', $this->responseBodyOf($record));
        self::assertStringNotContainsString('live-secret-42', $record->message);
        self::assertStringNotContainsString('live-secret-42', (string) json_encode($record->context));
    }

    public function testApiKeyIsRedactedBeforeTruncationSoATrailingMatchIsNotHalfLeft(): void
    {
        [$logger, $handler] = $this->logger();
        $apiKey = 'trailing-secret-key';
        // Тело ровно на границе усечения: ключ стоит так, что окно
        // усечения (2000 символов) разрезало бы его пополам, если бы
        // вычищение шло после обрезки, а не до неё.
        $padding = str_repeat('x', 2000 - mb_strlen($apiKey));
        $body = $padding.$apiKey;

        $failure = $this->httpFailure(401, $body);

        $logger->log('company-1', 'account-1', 'returns', $failure, $apiKey);

        $record = $handler->getRecords()[0];
        self::assertStringNotContainsString($apiKey, $this->responseBodyOf($record));
    }

    public function testResponseBodyIsTruncatedToAReasonableLength(): void
    {
        [$logger, $handler] = $this->logger();
        $failure = $this->httpFailure(401, str_repeat('a', 5000));

        $logger->log('company-1', 'account-1', 'products', $failure, '');

        $record = $handler->getRecords()[0];
        self::assertLessThanOrEqual(2000, mb_strlen($this->responseBodyOf($record)));
    }

    public function testNonHttpClientFailureIsLoggedAsBodyUnavailableRatherThanSilently(): void
    {
        [$logger, $handler] = $this->logger();

        $logger->log('company-1', 'account-1', 'sales', new \RuntimeException('неожиданный тип отказа'), 'key');

        $record = $handler->getRecords()[0];
        self::assertNull($record->context['status_code']);
        self::assertSame('(тело ответа недоступно)', $record->context['response_body']);
    }

    private function responseBodyOf(LogRecord $record): string
    {
        $body = $record->context['response_body'] ?? null;
        self::assertIsString($body);

        return $body;
    }

    /**
     * @return array{0: OzonAccountBrokenLogger, 1: TestHandler}
     */
    private function logger(): array
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        return [new OzonAccountBrokenLogger($logger), $handler];
    }

    private function httpFailure(int $status, string $body): ExceptionInterface
    {
        $client = new MockHttpClient(new MockResponse($body, ['http_code' => $status]));

        try {
            $client->request('POST', 'https://api-seller.ozon.ru/v2/posting/fbo/list')->getContent();
        } catch (ExceptionInterface $failure) {
            return $failure;
        }

        self::fail("Ответ {$status} обязан бросить исключение http-client.");
    }
}
