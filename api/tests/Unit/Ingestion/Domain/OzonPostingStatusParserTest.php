<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonPostingStatusParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class OzonPostingStatusParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/ozon/ozon-buyout-posting-statuses-after.json';

    public function testProducesOneObservationPerPostingRatherThanPerProduct(): void
    {
        $body = json_encode([
            'result' => [[
                'posting_number' => 'TEST-POSTING-MULTI',
                'order_number' => 'TEST-ORDER-MULTI',
                'status' => 'delivering',
                'substatus' => 'posting_on_way_to_city',
                'cancel_reason_id' => 0,
                'products' => [
                    ['sku' => 100001],
                    ['sku' => 100002],
                ],
            ]],
        ], \JSON_THROW_ON_ERROR);

        $statuses = $this->parser()->parse(
            $body,
            Uuid::v7(),
            Uuid::v7(),
            Uuid::v7(),
            new \DateTimeImmutable('2026-08-30 10:00:00'),
        );

        self::assertCount(1, $statuses);
        self::assertSame('TEST-POSTING-MULTI', $statuses[0]->postingNumber());
        self::assertSame('TEST-ORDER-MULTI', $statuses[0]->orderNumber());
    }

    public function testMapsEverySyntheticPostingAndTraceFields(): void
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);
        $companyId = Uuid::v7();
        $accountId = Uuid::v7();
        $rawDocumentId = Uuid::v7();
        $observedAt = new \DateTimeImmutable('2026-08-30 10:00:00');

        $statuses = $this->parser()->parse($body, $companyId, $accountId, $rawDocumentId, $observedAt);

        self::assertCount(7, $statuses);
        self::assertSame($companyId, $statuses[0]->companyId());
        self::assertSame($accountId, $statuses[0]->marketplaceAccountId());
        self::assertSame($rawDocumentId, $statuses[0]->rawDocumentId());
        self::assertSame($observedAt, $statuses[0]->observedAt());
        self::assertSame('cancelled', $statuses[0]->status());
        self::assertSame('posting_canceled', $statuses[0]->substatus());
        self::assertSame(506, $statuses[0]->cancelReasonId());
    }

    public function testPreservesMissingOptionalFieldsAsNull(): void
    {
        $body = json_encode([
            'result' => [[
                'posting_number' => 'TEST-POSTING-NULL',
                'order_number' => 'TEST-ORDER-NULL',
                'status' => 'cancelled',
            ]],
        ], \JSON_THROW_ON_ERROR);

        $statuses = $this->parser()->parse(
            $body,
            Uuid::v7(),
            Uuid::v7(),
            Uuid::v7(),
            new \DateTimeImmutable('2026-08-30 10:00:00'),
        );

        self::assertNull($statuses[0]->substatus());
        self::assertNull($statuses[0]->cancelReasonId());
    }

    #[DataProvider('requiredFields')]
    public function testMissingRequiredFieldFailsLoudly(string $field): void
    {
        $posting = [
            'posting_number' => 'TEST-POSTING-REQUIRED',
            'order_number' => 'TEST-ORDER-REQUIRED',
            'status' => 'delivered',
        ];
        unset($posting[$field]);
        $body = json_encode(['result' => [$posting]], \JSON_THROW_ON_ERROR);

        $this->expectException(\UnexpectedValueException::class);

        $this->parser()->parse(
            $body,
            Uuid::v7(),
            Uuid::v7(),
            Uuid::v7(),
            new \DateTimeImmutable('2026-08-30 10:00:00'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredFields(): iterable
    {
        yield 'posting number' => ['posting_number'];
        yield 'order number' => ['order_number'];
        yield 'status' => ['status'];
    }

    private function parser(): OzonPostingStatusParser
    {
        return new OzonPostingStatusParser();
    }
}
