<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonProductListParser;
use PHPUnit\Framework\TestCase;

/**
 * Парсер проверяется на зафиксированном ответе настоящего кабинета
 * (CLAUDE.md §9: обращений к внешним API в тестах нет).
 */
final class OzonProductListParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/ozon/product-list-2026-08-13.json';

    public function testParsesSkusFromRealCabinetResponse(): void
    {
        $page = (new OzonProductListParser())->parse($this->fixture());

        self::assertCount(62, $page->skus);
        self::assertSame(62, $page->itemsOnPage);
        // Артикул из этой же фикстуры, он же встречается в фикстуре
        // продаж — на нём и склеиваются каталог с фактами.
        self::assertContains('220280923', $page->skus);
        self::assertNotSame('', $page->lastId);
    }

    public function testNumericSkuBecomesTheStringUsedBySalesFacts(): void
    {
        // Площадка отдаёт sku числом, а marketplace_sku в базе — строка
        // (varchar(64), общий столбец с sales_fact). Приведение делает
        // парсер: сравнение числа со строкой в SQL молча не нашло бы
        // ничего, и оверлей считал бы все карточки чужими.
        $page = (new OzonProductListParser())->parse(
            '{"result":{"items":[{"product_id":1,"offer_id":"A","sku":220280923}],"total":1,"last_id":""}}',
        );

        self::assertSame(['220280923'], $page->skus);
    }

    public function testProductWithoutCardIsSkipped(): void
    {
        // sku = 0 у товара, которому площадка ещё не завела карточку:
        // встретить его на сайте нельзя, и в каталоге «своих карточек»
        // ему делать нечего. Разбор при этом не падает — иначе один
        // недооформленный товар останавливал бы синхронизацию каталога
        // целиком.
        $page = (new OzonProductListParser())->parse(
            '{"result":{"items":[{"product_id":1,"offer_id":"A","sku":0},{"product_id":2,"offer_id":"B","sku":220280923}],"total":2,"last_id":""}}',
        );

        self::assertSame(['220280923'], $page->skus);
        self::assertSame(2, $page->itemsOnPage);
    }

    public function testEmptyCursorMeansLastPage(): void
    {
        $page = (new OzonProductListParser())->parse('{"result":{"items":[],"total":0,"last_id":""}}');

        self::assertSame([], $page->skus);
        self::assertSame('', $page->lastId);
    }

    public function testResponseWithoutResultIsRejected(): void
    {
        // Ошибка площадки приходит с кодом и сообщением вместо result.
        // Пустой каталог из неё делать нельзя: replaceForAccount стёр бы
        // все товары продавца.
        $this->expectException(\UnexpectedValueException::class);

        (new OzonProductListParser())->parse('{"code":16,"message":"Client-Id and Api-Key headers are required"}');
    }

    private function fixture(): string
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);

        return $body;
    }
}
