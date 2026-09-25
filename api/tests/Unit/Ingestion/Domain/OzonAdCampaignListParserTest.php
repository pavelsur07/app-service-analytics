<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonAdCampaign;
use App\Ingestion\Domain\OzonAdCampaignListParser;
use App\Ingestion\Domain\OzonAdCampaignProductsParser;
use PHPUnit\Framework\TestCase;

/**
 * Разбор на зафиксированных ответах рекламного кабинета первого клиента
 * (CLAUDE.md §9, docs/task/ozon-advertising-research.md).
 */
final class OzonAdCampaignListParserTest extends TestCase
{
    private const string DIR = __DIR__.'/../../../Fixtures/Marketplace/ozon/performance/';

    public function testEveryCampaignOfTheCabinetIsParsed(): void
    {
        $campaigns = (new OzonAdCampaignListParser())->parse($this->fixture('campaign-list.json'));

        self::assertCount(94, $campaigns);
        $running = array_filter($campaigns, static fn (OzonAdCampaign $c): bool => 'CAMPAIGN_STATE_RUNNING' === $c->state);
        self::assertCount(2, $running);
    }

    public function testMissingStateIsAParseErrorNotAnEmptyValue(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new OzonAdCampaignListParser())->parse('{"list":[{"id":"1","advObjectType":"SKU","createdAt":"2026-09-01T09:08:29Z"}]}');
    }

    public function testProductSkusOfACampaignAreParsed(): void
    {
        $skus = (new OzonAdCampaignProductsParser())->skus($this->fixture('campaign-29088934-products.json'));

        self::assertCount(10, $skus);
        self::assertContains('4193184961', $skus);
    }

    private function fixture(string $name): string
    {
        $body = file_get_contents(self::DIR.$name);
        self::assertIsString($body);

        return $body;
    }
}
