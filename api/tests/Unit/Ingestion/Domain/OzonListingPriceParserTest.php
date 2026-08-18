<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonListingPrice;
use App\Ingestion\Domain\OzonListingPriceParser;
use PHPUnit\Framework\TestCase;

/**
 * Разбор цен из /v3/product/info/list (ADR-015) на снятой с боевого
 * кабинета фикстуре — той же, на которой проверяется разбор
 * наименований (CLAUDE.md §9: обращений к внешним API в тестах нет).
 */
final class OzonListingPriceParserTest extends TestCase
{
    public function testReadsPricesFromTheRealResponse(): void
    {
        $prices = (new OzonListingPriceParser())->parse($this->fixture());

        self::assertCount(62, $prices, 'цена есть у всех позиций выгрузки');

        $first = $prices[0];
        self::assertInstanceOf(OzonListingPrice::class, $first);
        self::assertSame('4404411630', $first->marketplaceSku);
        // «3300.00» и «3900.00» из ответа площадки, в минорных единицах.
        self::assertSame(330000, $first->price->minorAmount());
        self::assertSame(390000, $first->oldPrice?->minorAmount());
        self::assertSame('RUB', $first->price->currency());
    }

    /**
     * Копейки не размываются: суммы приходят строками, и разбор
     * целочисленный — float не появляется даже промежуточным значением
     * (ADR-004).
     */
    public function testParsesKopecksExactly(): void
    {
        $prices = (new OzonListingPriceParser())->parse($this->response([
            ['sku' => 1, 'currency_code' => 'RUB', 'price' => '420.10', 'old_price' => '0.00'],
            ['sku' => 2, 'currency_code' => 'RUB', 'price' => '1234.05', 'old_price' => '2000'],
            ['sku' => 3, 'currency_code' => 'RUB', 'price' => '99.9', 'old_price' => '100.00'],
        ]));

        self::assertSame(42010, $prices[0]->price->minorAmount());
        // «0.00» — это отсутствие зачёркнутой цены, а не скидка до нуля.
        self::assertNull($prices[0]->oldPrice);
        self::assertSame(123405, $prices[1]->price->minorAmount());
        self::assertSame(200000, $prices[1]->oldPrice?->minorAmount());
        self::assertSame(9990, $prices[2]->price->minorAmount(), '99.9 — это 99 рублей 90 копеек, не 9 копеек');
    }

    public function testSkipsEntriesWithoutUsablePriceOrCurrency(): void
    {
        // Товар заведён, но не выставлен, — не повод потерять остальные.
        $prices = (new OzonListingPriceParser())->parse($this->response([
            ['sku' => 1, 'currency_code' => 'RUB', 'price' => '0.00'],
            ['sku' => 2, 'currency_code' => '', 'price' => '100.00'],
            ['sku' => 0, 'currency_code' => 'RUB', 'price' => '100.00'],
            ['sku' => 3, 'currency_code' => 'RUB', 'price' => '100.00'],
        ]));

        self::assertCount(1, $prices);
        self::assertSame('3', $prices[0]->marketplaceSku);
    }

    public function testResponseWithoutItemsIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new OzonListingPriceParser())->parse('{"result":{}}');
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function response(array $items): string
    {
        return json_encode(['items' => $items], \JSON_THROW_ON_ERROR);
    }

    private function fixture(): string
    {
        $body = file_get_contents(__DIR__.'/../../../Fixtures/Marketplace/ozon/product-info-list-2026-08-13.json');
        self::assertIsString($body);

        return $body;
    }
}
