<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonCampaignCatalogParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OzonCampaignCatalogParserTest extends TestCase
{
    public function testProductionFixtureKeepsNamesAndCapitalPaymentTypeWithoutMoney(): void
    {
        $body = file_get_contents(__DIR__.'/../../../Fixtures/Marketplace/ozon/performance/campaign-list.json');
        self::assertIsString($body);
        $data = (new OzonCampaignCatalogParser())->parse($body);
        self::assertTrue($data->complete);
        self::assertSame(94, $data->total);
        self::assertCount(94, $data->items);
        self::assertContains('CPC', array_map(static fn ($item) => $item->paymentType, $data->items));
        self::assertNotEmpty($data->items[0]->title);
        self::assertSame(['id', 'title', 'state', 'advObjectType', 'PaymentType'], array_keys($data->items[0]->metadata()));
    }

    public function testConfirmedEmptyAndUnknownOrPartialTotalDiffer(): void
    {
        $parser = new OzonCampaignCatalogParser();
        self::assertTrue($parser->parse('{"list":[],"total":"0"}')->complete);
        self::assertFalse($parser->parse('{"list":[]}')->complete);
        self::assertFalse($parser->parse('{"list":[],"total":2}')->complete);
    }

    /** @return iterable<string, array{string, class-string<\Throwable>}> */
    public static function invalid(): iterable
    {
        yield 'json' => ['{', \JsonException::class];
        yield 'missing list' => ['{"total":0}', \UnexpectedValueException::class];
        yield 'fractional total' => ['{"list":[],"total":0.5}', \UnexpectedValueException::class];
        yield 'negative total' => ['{"list":[],"total":-1}', \UnexpectedValueException::class];
        yield 'map list' => ['{"list":{"x":1},"total":1}', \UnexpectedValueException::class];
        yield 'bad item' => ['{"list":[{"id":"1"}],"total":1}', \UnexpectedValueException::class];
        $row = '{"id":"1","state":"CAMPAIGN_STATE_RUNNING","advObjectType":"SKU"}';
        yield 'duplicate' => ['{"list":['.$row.','.$row.'],"total":2}', \UnexpectedValueException::class];
        yield 'total too small' => ['{"list":['.$row.'],"total":0}', \UnexpectedValueException::class];
    }

    /** @param class-string<\Throwable> $exception */
    #[DataProvider('invalid')]
    public function testRejectsMalformedCatalog(string $body, string $exception): void
    {
        $this->expectException($exception);
        (new OzonCampaignCatalogParser())->parse($body);
    }

    public function testSkuWithoutPaymentTypeIsNotInventedCpc(): void
    {
        $data = (new OzonCampaignCatalogParser())->parse('{"list":[{"id":"1","state":"CAMPAIGN_STATE_RUNNING","advObjectType":"SKU"}],"total":1}');
        self::assertNull($data->items[0]->paymentType);
        self::assertNull($data->items[0]->title);
    }
}
