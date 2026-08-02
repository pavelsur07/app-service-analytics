<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Ui;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SellerPingControllerTest extends WebTestCase
{
    public function testPingRespondsWithAppInfo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/seller/ping');

        self::assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array{app: string, version: string, respondedAt: string} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame('conwix-seller-api', $payload['app']);
        self::assertArrayHasKey('version', $payload);
        self::assertArrayHasKey('respondedAt', $payload);
    }
}
