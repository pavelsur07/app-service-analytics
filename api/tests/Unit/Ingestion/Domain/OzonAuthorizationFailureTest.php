<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonAuthorizationFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/**
 * Цена ошибки здесь несимметрична: ложное срабатывание останавливает
 * исправную синхронизацию и пугает клиента письмом, пропуск — оставляет
 * отозванный ключ незамеченным. Поэтому проверяется и то, и другое.
 */
final class OzonAuthorizationFailureTest extends TestCase
{
    public function testUnauthorizedAndForbiddenAreAuthorizationFailures(): void
    {
        self::assertTrue(OzonAuthorizationFailure::isAuthorizationFailure($this->httpFailure(401)));
        self::assertTrue(OzonAuthorizationFailure::isAuthorizationFailure($this->httpFailure(403)));
    }

    public function testRateLimitAndServerErrorAreNot(): void
    {
        // Лимит запросов и сбой площадки лечатся повтором, а не
        // переподключением кабинета: подключение обязано остаться active,
        // а сообщение — уйти в ретрай.
        self::assertFalse(OzonAuthorizationFailure::isAuthorizationFailure($this->httpFailure(429)));
        self::assertFalse(OzonAuthorizationFailure::isAuthorizationFailure($this->httpFailure(500)));
        self::assertFalse(OzonAuthorizationFailure::isAuthorizationFailure($this->httpFailure(400)));
    }

    public function testNonHttpFailureIsNot(): void
    {
        // Сетевой сбой или ошибка разбора — не повод объявлять ключ
        // отозванным. Именно так выглядел бы обрыв связи с площадкой.
        self::assertFalse(OzonAuthorizationFailure::isAuthorizationFailure(new \RuntimeException('сеть недоступна')));
    }

    private function httpFailure(int $status): ExceptionInterface
    {
        $client = new MockHttpClient(new MockResponse('{"code":16}', ['http_code' => $status]));

        try {
            $client->request('POST', 'https://api-seller.ozon.ru/v3/product/list')->getContent();
        } catch (ExceptionInterface $failure) {
            return $failure;
        }

        self::fail("Ответ {$status} обязан бросить исключение http-client.");
    }
}
