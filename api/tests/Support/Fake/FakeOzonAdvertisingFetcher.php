<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake;

use App\Ingestion\Domain\OzonAdvertisingFetcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Клиент рекламного API Ozon на снятых фикстурах (ADR-005): отдаёт
 * переданные ответы и записывает, что у него спрашивали.
 */
final class FakeOzonAdvertisingFetcher implements OzonAdvertisingFetcher
{
    public int $calls = 0;

    public function __construct(
        private readonly int $tokenStatus,
        private readonly string $campaigns,
        private readonly string $expense,
        private readonly string $daily,
        private readonly ?\Closure $beforeRejection,
        private readonly string $sku,
        private readonly string $reportRequest,
        private readonly string $reportBody,
    ) {
    }

    public function token(string $clientId, string $clientSecret): string
    {
        ++$this->calls;
        if (200 !== $this->tokenStatus) {
            if (null !== $this->beforeRejection) {
                ($this->beforeRejection)();
            }
            // Настоящее исключение symfony/http-client: распознавание
            // отказа авторизации смотрит на код ответа внутри него.
            $client = new MockHttpClient(new MockResponse('{"error":"invalid_client"}', ['http_code' => $this->tokenStatus]));
            $client->request('POST', 'https://api-performance.ozon.ru/api/client/token')->getContent();
        }

        return 'jwt';
    }

    public function campaigns(string $token): string
    {
        ++$this->campaignCalls;

        return $this->campaigns;
    }

    public function campaignProducts(string $token, string $campaignId): string
    {
        throw new \LogicException('Загрузка рекламы товары кампаний не запрашивает.');
    }

    public function expense(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return $this->expense;
    }

    public function daily(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        return $this->daily;
    }

    /** @var list<array{day: string, campaigns: list<string>}> */
    public array $skuRequests = [];

    /** @var list<array{from: string, to: string, campaigns: list<string>}> */
    public array $reportOrders = [];

    /** @var list<string> Состояния, которые по очереди вернёт reportState(). */
    public array $states = ['OK'];

    public int $campaignCalls = 0;

    public ?\Throwable $orderFailure = null;

    public function failOrdersWith(\Throwable $failure): void
    {
        $this->orderFailure = $failure;
    }

    /**
     * @param list<string> $states
     */
    public function reportStates(array $states): void
    {
        $this->states = $states;
    }

    public function orderSkuReport(string $token, array $campaignIds, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        if (null !== $this->orderFailure) {
            throw $this->orderFailure;
        }
        $this->reportOrders[] = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'campaigns' => $campaignIds];

        return $this->reportRequest;
    }

    /** @var list<array{from: string, to: string}> */
    public array $cpoOrders = [];

    public function orderCpoOrdersReport(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $this->cpoOrders[] = ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];

        return $this->reportRequest;
    }

    public function reportState(string $token, string $uuid): string
    {
        $state = array_shift($this->states) ?? 'IN_PROGRESS';

        return json_encode(['UUID' => $uuid, 'state' => $state], \JSON_THROW_ON_ERROR);
    }

    public function report(string $token, string $uuid): string
    {
        return $this->reportBody;
    }

    public function productsSku(string $token, array $campaignIds, \DateTimeImmutable $day): string
    {
        $this->skuRequests[] = ['day' => $day->format('Y-m-d'), 'campaigns' => $campaignIds];

        return $this->sku;
    }
}
