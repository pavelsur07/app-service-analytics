<?php

declare(strict_types=1);

namespace App\Tests\Functional\Links;

use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Links\Domain\BotDetector;
use App\Links\Domain\ShortLink;
use App\Links\Domain\ShortLinkClick;
use App\Links\Domain\ShortLinkClickRepository;
use App\Links\Domain\ShortLinkStatus;
use App\Links\Infrastructure\Persistence\DoctrineShortLinkRepository;
use App\Links\Infrastructure\Query\ActiveShortLinkQuery;
use App\Links\Ui\Controller\RedirectShortLinkController;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\ShortLinkBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\AbstractLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

final class RedirectShortLinkControllerTest extends WebTestCase
{
    public function testActiveLinkRedirectsWithoutCachingAndRecordsAHumanClick(): void
    {
        $client = static::createClient();
        $link = $this->link();

        $this->request($client, $link);

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', $link->targetUrl());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));

        $row = $this->connection()->fetchAssociative(
            'SELECT user_agent, referer, is_bot FROM short_link_click WHERE short_link_id = ?',
            [$link->id()->toRfc4122()],
        );
        self::assertIsArray($row);
        self::assertSame('Mozilla/5.0 Chrome/130.0 Safari/537.36', $row['user_agent']);
        self::assertSame('https://example.com/newsletter', $row['referer']);
        self::assertFalse($row['is_bot']);
    }

    public function testPublicRouteDoesNotExistOnTheAdminHost(): void
    {
        $client = static::createClient();
        $link = $this->link();

        $this->request($client, $link, 'admin.conwix.localhost');

        self::assertResponseStatusCodeSame(404);
        $count = $this->connection()->fetchOne(
            'SELECT count(*) FROM short_link_click WHERE short_link_id = ?',
            [$link->id()->toRfc4122()],
        );
        self::assertTrue(is_numeric($count));
        self::assertSame(0, (int) $count);
    }

    public function testDisabledAndUnknownCodesReturnNotFound(): void
    {
        $client = static::createClient();
        $disabled = $this->link(ShortLinkStatus::Disabled);

        $this->request($client, $disabled);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/Unknown', server: ['HTTP_HOST' => 'lin.conwix.localhost']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testClickStorageFailureDoesNotBlockRedirect(): void
    {
        static::createClient();
        $link = $this->link();
        $failure = new \RuntimeException('Synthetic click storage failure.', 73);
        $logger = new CapturingLogger();
        $controller = new RedirectShortLinkController(
            new ActiveShortLinkQuery($this->connection()),
            new FailingShortLinkClickRepository($failure),
            new BotDetector(),
            $logger,
        );
        $request = Request::create('/'.$link->code(), server: [
            'HTTP_HOST' => 'lin.conwix.localhost',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/130.0 Safari/537.36',
        ]);

        $response = $controller($link->code(), $request);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame($link->targetUrl(), $response->headers->get('Location'));
        self::assertSame($failure, $logger->warningContext()['exception'] ?? null);
    }

    private function link(ShortLinkStatus $status = ShortLinkStatus::Active): ShortLink
    {
        $entityManager = $this->entityManager();
        $administrator = AdministratorBuilder::anAdministrator()->persistWith(
            new DoctrineAdministratorRepository($entityManager),
        );

        return ShortLinkBuilder::aShortLink()
            ->withStatus($status)
            ->withCreatedByAdminId($administrator->id())
            ->persistWith(new DoctrineShortLinkRepository($entityManager));
    }

    private function request(
        KernelBrowser $client,
        ShortLink $link,
        string $host = 'lin.conwix.localhost',
    ): void {
        $client->request(
            'GET',
            '/'.$link->code(),
            server: [
                'HTTP_HOST' => $host,
                'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/130.0 Safari/537.36',
                'HTTP_REFERER' => 'https://example.com/newsletter',
            ],
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }
}

final class FailingShortLinkClickRepository implements ShortLinkClickRepository
{
    public function __construct(
        private readonly \Throwable $failure,
    ) {
    }

    public function record(ShortLinkClick $click): void
    {
        throw $this->failure;
    }
}

final class CapturingLogger extends AbstractLogger
{
    /** @var array<string, mixed> */
    private array $warningContext = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ('warning' === $level) {
            $this->warningContext = $context;
        }
    }

    /** @return array<string, mixed> */
    public function warningContext(): array
    {
        return $this->warningContext;
    }
}
