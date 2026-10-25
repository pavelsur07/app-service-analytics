<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\ServiceCoverage\CaptureServiceCoverageAction;
use App\Ingestion\Infrastructure\Persistence\DbalServiceCoverageCaptureStore;
use App\Ingestion\Infrastructure\ServiceCoverage\OzonFetcherCoverageClient;
use App\Ingestion\Infrastructure\ServiceCoverage\ServiceCoverageState;
use App\Tests\Support\Builder\ServiceCoverageCaptureBuilder;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Снятие сводки покрытия по расписанию: окно 93 дня по Москве, upsert
 * одной строки на сервис; отказ обновляет исход попытки и не стирает
 * последнюю удачную сводку — экран показывает её со временем.
 */
final class CaptureServiceCoverageActionTest extends KernelTestCase
{
    private const string CONTRACT = '/var/www/services/_contracts/service-coverage/v1/example.json';

    public function testItAsksTheContractWindowAndStoresTheParsedAnswer(): void
    {
        $asked = null;
        $action = $this->action(static function (string $method, string $url) use (&$asked): MockResponse {
            $asked = $url;

            return new MockResponse(self::example());
        });

        // 25.10 22:30 UTC — по Москве уже 26.10.
        $state = $action(new \DateTimeImmutable('2026-10-25T22:30:00Z'));

        self::assertSame(ServiceCoverageState::Ok, $state);
        self::assertSame('http://ozon-fetcher.test:8080/internal/coverage?from=2026-07-26&to=2026-10-26', $asked);
        $capture = $this->store()->latest(CaptureServiceCoverageAction::SERVICE);
        self::assertNotNull($capture);
        self::assertSame(ServiceCoverageState::Ok, $capture->state);
        self::assertSame('2026-10-25T22:30:00+00:00', $capture->attemptedAt->format(\DATE_ATOM));
        self::assertSame('2026-10-25T22:30:00+00:00', $capture->succeededAt?->format(\DATE_ATOM));
        self::assertCount(5, $capture->lastSuccess->rows ?? []);
    }

    public function testFailureKeepsTheLastSuccessfulCapture(): void
    {
        ServiceCoverageCaptureBuilder::aCapture()
            ->withPayload(self::example())
            ->withAt(new \DateTimeImmutable('2026-10-25T09:00:00Z'))
            ->persistWith($this->store());

        $action = $this->action(static fn (): MockResponse => new MockResponse('', ['http_code' => 500]));
        self::assertSame(ServiceCoverageState::Unavailable, $action(new \DateTimeImmutable('2026-10-25T09:05:00Z')));
        // Повтор той же попытки строку не размножает.
        $action(new \DateTimeImmutable('2026-10-25T09:05:00Z'));

        $capture = $this->store()->latest(CaptureServiceCoverageAction::SERVICE);
        self::assertNotNull($capture);
        self::assertSame(ServiceCoverageState::Unavailable, $capture->state);
        self::assertSame('2026-10-25T09:05:00+00:00', $capture->attemptedAt->format(\DATE_ATOM));
        self::assertSame('2026-10-25T09:00:00+00:00', $capture->succeededAt?->format(\DATE_ATOM));
        self::assertSame('2026-10-25T09:00:00+00:00', $capture->lastSuccess?->generatedAt?->format(\DATE_ATOM));
        self::assertEquals(1, $this->connection()->fetchOne('SELECT count(*) FROM service_coverage_capture'));
    }

    public function testNothingCapturedYetIsNull(): void
    {
        self::assertNull($this->store()->latest(CaptureServiceCoverageAction::SERVICE));
    }

    public function testFirstAttemptFailingStoresStateWithoutCapture(): void
    {
        $action = new CaptureServiceCoverageAction(
            new OzonFetcherCoverageClient(new MockHttpClient(), new NullLogger(), 'false', 'http://ozon-fetcher.test:8080', 'token'),
            $this->store(),
        );

        self::assertSame(ServiceCoverageState::Disabled, $action(new \DateTimeImmutable('2026-10-25T09:00:00Z')));

        $capture = $this->store()->latest(CaptureServiceCoverageAction::SERVICE);
        self::assertNotNull($capture);
        self::assertSame(ServiceCoverageState::Disabled, $capture->state);
        self::assertNull($capture->succeededAt);
        self::assertNull($capture->lastSuccess);
    }

    /**
     * @param callable(string, string, array<string, mixed>): MockResponse $responder
     */
    private function action(callable $responder): CaptureServiceCoverageAction
    {
        return new CaptureServiceCoverageAction(
            new OzonFetcherCoverageClient(new MockHttpClient($responder), new NullLogger(), 'true', 'http://ozon-fetcher.test:8080', 'token'),
            $this->store(),
        );
    }

    private function store(): DbalServiceCoverageCaptureStore
    {
        /** @var DbalServiceCoverageCaptureStore $store */
        $store = static::getContainer()->get(DbalServiceCoverageCaptureStore::class);

        return $store;
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);

        return $connection;
    }

    private static function example(): string
    {
        $example = file_get_contents(self::CONTRACT);
        self::assertIsString($example);

        return $example;
    }
}
