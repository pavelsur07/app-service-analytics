<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SessionRequestProtectionTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $pool = self::getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $pool);
        self::assertTrue($pool->clear());
    }

    #[DataProvider('administratorPaths')]
    public function testSiblingOriginCannotCreateAdministratorThroughSuperAdminSession(string $path): void
    {
        $client = $this->client;
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->persistWith(new DoctrineAdministratorRepository($entityManager));
        $client->loginUser($boss, 'admin');

        $client->request('POST', $path, server: [
            'CONTENT_TYPE' => 'text/plain',
            'HTTP_ORIGIN' => 'https://compromised.conwix.com',
        ], content: '{"email":"attacker@example.test","password":"attacker-password-1234"}');

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($entityManager->getConnection()->fetchOne(
            'SELECT id FROM administrator WHERE email = :email', ['email' => 'attacker@example.test'],
        ));
    }

    /** @return iterable<string, array{string}> */
    public static function administratorPaths(): iterable
    {
        yield 'literal path' => ['/api/admin/administrators'];
        yield 'encoded api prefix' => ['/%61pi/admin/administrators'];
        yield 'encoded admin prefix' => ['/api/%61dmin/administrators'];
    }

    public function testParsedMultipartCannotLogOutSeller(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $user = UserBuilder::aUser()->persistWith(new DoctrineUserRepository($entityManager));
        $this->client->loginUser($user, 'api');
        $this->client->request('POST', '/api/auth/logout', parameters: ['field' => 'value'], server: [
            'HTTP_X_CONWIX_REQUEST' => '1',
            'CONTENT_TYPE' => 'multipart/form-data; boundary=example',
        ]);
        self::assertResponseStatusCodeSame(415);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    /** @param array<string, string> $headers */
    #[DataProvider('rejectedRequests')]
    public function testUnsafeRequestsAreRejectedBeforeAuthentication(string $path, string $method, array $headers, string $body, int $status): void
    {
        $client = $this->client;
        $client->request($method, $path, server: $headers, content: $body);

        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
    }

    /** @return iterable<string, array{string, string, array<string, string>, string, int}> */
    public static function rejectedRequests(): iterable
    {
        yield 'missing header before login' => ['/api/auth/login', 'POST', ['CONTENT_TYPE' => 'application/json'], '{}', 403];
        yield 'missing header before admin logout' => ['/api/admin/auth/logout', 'POST', [], '', 403];
        yield 'missing header before seller logout' => ['/api/auth/logout', 'POST', [], '', 403];
        yield 'invalid header' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '0'], '', 403];
        yield 'null origin' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'HTTP_ORIGIN' => 'null'], '', 403];
        yield 'sibling with custom header' => ['/api/admin/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'HTTP_ORIGIN' => 'https://compromised.conwix.com'], '', 403];
        yield 'seller cannot use admin origin' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'HTTP_ORIGIN' => 'http://admin.conwix.internal'], '', 403];
        yield 'admin cannot use seller origin' => ['/api/admin/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'HTTP_ORIGIN' => 'http://app.conwix.internal'], '', 403];
        yield 'suffix host' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'HTTP_ORIGIN' => 'http://app.conwix.localhost.evil.test'], '', 403];
        yield 'wrong port' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'HTTP_ORIGIN' => 'http://app.conwix.localhost:12345'], '', 403];
        yield 'foreign referer' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'HTTP_REFERER' => 'https://evil.test/form'], '', 403];
        yield 'forged bearer does not bypass session protection' => ['/api/auth/login', 'POST', ['HTTP_AUTHORIZATION' => 'Bearer forged'], '', 403];
        yield 'plain text JSON' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'CONTENT_TYPE' => 'text/plain'], '{}', 415];
        yield 'form' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'email=x', 415];
        yield 'multipart' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1', 'CONTENT_TYPE' => 'multipart/form-data; boundary=x'], 'x', 415];
        yield 'missing content type' => ['/api/auth/login', 'POST', ['HTTP_X_CONWIX_REQUEST' => '1'], '{}', 415];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('allowedRequests')]
    public function testValidProtectionReachesAuthentication(string $path, array $headers): void
    {
        $client = $this->client;
        $client->request('POST', $path, server: ['HTTP_X_CONWIX_REQUEST' => '1', ...$headers], content: '{"email":"nobody@example.test","password":"wrong-password"}');

        self::assertResponseStatusCodeSame(401);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function allowedRequests(): iterable
    {
        yield 'seller origin' => ['/api/auth/login', ['CONTENT_TYPE' => 'application/json; charset=utf-8', 'HTTP_ORIGIN' => 'http://app.conwix.internal']];
        yield 'admin origin' => ['/api/admin/auth/login', ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://admin.conwix.internal']];
        yield 'referer fallback' => ['/api/auth/login', ['CONTENT_TYPE' => 'application/json', 'HTTP_REFERER' => 'http://app.conwix.localhost/login?next=1']];
        yield 'header without origin or referer' => ['/api/auth/login', ['CONTENT_TYPE' => 'application/json']];
    }

    #[DataProvider('logoutPaths')]
    public function testGetCannotLogOutAnAuthenticatedSeller(string $path): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $user = UserBuilder::aUser()->persistWith(
            new DoctrineUserRepository($entityManager),
        );
        $this->client->loginUser($user, 'api');
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(405);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{string}> */
    public static function logoutPaths(): iterable
    {
        yield 'literal seller' => ['/api/auth/logout'];
        yield 'encoded seller' => ['/api/auth/%6cogout'];
        yield 'encoded api' => ['/%61pi/auth/logout'];
        yield 'encoded admin' => ['/api/admin/auth/%6cogout'];
    }

    public function testBearerContourStillRequiresAuthenticationWithoutSessionHeaders(): void
    {
        $client = $this->client;
        $client->request('POST', '/api/extension/companies/01900000-0000-7000-8000-000000000001/tracked-skus', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');
        self::assertResponseStatusCodeSame(401);
    }
}
