<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonProductListItem;
use App\Ingestion\Domain\OzonProductListPage;
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

        self::assertCount(62, $page->items);
        self::assertSame(62, $page->itemsOnPage);
        // Артикул из этой же фикстуры, он же встречается в фикстуре
        // продаж — на нём и склеиваются каталог с фактами.
        self::assertContains('220280923', $this->skus($page));
        self::assertNotSame('', $page->lastId);
    }

    public function testSellerArticleAndProductIdComeFromTheSameResponse(): void
    {
        $page = (new OzonProductListParser())->parse($this->fixture());

        // Артикул продавца достаётся бесплатно — тем же запросом,
        // который каталог уже делает каждый тик. Наименования в этом
        // ответе нет вовсе, оно берётся вторым запросом.
        $item = $page->items[0];
        self::assertNotSame('', $item->offerId);
        self::assertGreaterThan(0, $item->productId);

        // product_id нужен ровно для запроса имён, и страница отдаёт
        // их списком, чтобы вызывающему не собирать его самому.
        self::assertCount(62, $page->productIds());
    }

    public function testProductWithoutSellerArticleIsRejected(): void
    {
        // Артикул у товара обязателен: им товар заводят в кабинете.
        // Пустая строка вместо него означала бы карточку, которую
        // селлер не опознает на экране ввода себестоимости, — а ради
        // этого экрана артикул и берётся.
        $this->expectException(\UnexpectedValueException::class);

        (new OzonProductListParser())->parse(
            '{"result":{"items":[{"product_id":1,"sku":220280923}],"total":1,"last_id":""}}',
        );
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

        self::assertSame(['220280923'], $this->skus($page));
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

        self::assertSame(['220280923'], $this->skus($page));
        self::assertSame(2, $page->itemsOnPage);
    }

    public function testEmptyCursorMeansLastPage(): void
    {
        $page = (new OzonProductListParser())->parse('{"result":{"items":[],"total":0,"last_id":""}}');

        self::assertSame([], $page->items);
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

    /**
     * @return list<string>
     */
    private function skus(OzonProductListPage $page): array
    {
        return array_map(
            static fn (OzonProductListItem $item): string => $item->sku,
            $page->items,
        );
    }

    private function fixture(): string
    {
        $body = file_get_contents(self::FIXTURE);
        self::assertIsString($body);

        return $body;
    }
}
