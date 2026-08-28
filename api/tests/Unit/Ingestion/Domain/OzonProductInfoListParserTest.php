<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

use App\Ingestion\Domain\OzonListingCard;
use App\Ingestion\Domain\OzonProductInfoListParser;
use PHPUnit\Framework\TestCase;

/**
 * Парсер проверяется на зафиксированном ответе настоящего кабинета
 * (CLAUDE.md §9: обращений к внешним API в тестах нет). Фикстура снята
 * тем же днём, что и фикстура каталога, — на этом и держится проверка
 * склейки.
 */
final class OzonProductInfoListParserTest extends TestCase
{
    private const string FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/ozon/product-info-list-2026-08-13.json';

    private const string CATALOG_FIXTURE = __DIR__.'/../../../Fixtures/Marketplace/ozon/product-list-2026-08-13.json';

    public function testParsesCardsFromRealCabinetResponse(): void
    {
        $cards = (new OzonProductInfoListParser())->parse($this->fixture(self::FIXTURE));

        self::assertCount(62, $cards);
        foreach ($cards as $sku => $card) {
            self::assertNotSame('', $sku);
            self::assertNotSame('', $card->name);
            // Фото есть у всех 62 позиций боевого ответа, и на этом
            // факте держится вся колонка превью. Перестанет — узнаем
            // здесь, а не серыми квадратами на экране клиента.
            self::assertNotNull($card->photoUrl);
            self::assertStringStartsWith('https://ir.ozone.ru/s3/', $card->photoUrl);
        }
    }

    public function testEveryCatalogSkuGetsAName(): void
    {
        // Ради этого и снимались обе фикстуры одним днём: склейка идёт
        // по sku, а не по product_id, и если бы ответы расходились хоть
        // на одну карточку, половина каталога осталась бы безымянной.
        $cards = (new OzonProductInfoListParser())->parse($this->fixture(self::FIXTURE));

        /** @var array{result: array{items: list<array{sku: int}>}} $catalog */
        $catalog = json_decode($this->fixture(self::CATALOG_FIXTURE), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($catalog['result']['items'] as $item) {
            self::assertArrayHasKey((string) $item['sku'], $cards);
        }
    }

    public function testEntryWithoutNameIsSkippedRatherThanStoredEmpty(): void
    {
        // Пустое имя от площадки не приходит — имя у карточки
        // обязательное. Придёт неполный ответ — писатель оставит
        // известное имя, а не затрёт его пустотой, и для этого его
        // здесь не должно быть вовсе.
        $cards = (new OzonProductInfoListParser())->parse(
            '{"items":[{"id":1,"sku":220280923,"offer_id":"A"},{"id":2,"sku":220280924,"offer_id":"B","name":"Топ"}]}',
        );

        // Карточка без имени не просто пуста — её нет вовсе.
        // Ключ сравнивается через has/not-has, а не списком: числовые
        // строки PHP превращает в int, и список ключей сравнивал бы
        // не то, что читается.
        self::assertCount(1, $cards);
        self::assertArrayNotHasKey('220280923', $cards);

        $card = reset($cards);
        self::assertInstanceOf(OzonListingCard::class, $card);
        self::assertSame('Топ', $card->name);
        // Имя есть, фото нет — карточка всё равно попадает в результат.
        self::assertNull($card->photoUrl);
    }

    /**
     * Кривое фото не должно ронять разбор — в отличие от цены, где
     * исключение осознанно. Здесь исключение уронило бы синхронизацию
     * каталога целиком: вместе с именами, артикулами и историей цен,
     * ради украшения.
     *
     * Отдельно проверяется http: адрес уходит в атрибут src, и это
     * граница доверия, а не придирка.
     */
    public function testPhotoOfUnexpectedShapeIsIgnoredRatherThanFatal(): void
    {
        $cards = (new OzonProductInfoListParser())->parse(
            '{"items":['
            .'{"id":1,"sku":1,"name":"Строкой","primary_image":"https://ir.ozone.ru/s3/multimedia-1-h/1.jpg"},'
            .'{"id":2,"sku":2,"name":"Пустым массивом","primary_image":[]},'
            .'{"id":3,"sku":3,"name":"По http","primary_image":["http://ir.ozone.ru/s3/multimedia-1-h/3.jpg"]},'
            .'{"id":4,"sku":4,"name":"Числом","primary_image":[42]}'
            .']}',
        );

        self::assertCount(4, $cards);
        foreach ($cards as $card) {
            self::assertNull($card->photoUrl, $card->name);
        }
    }

    public function testProductWithoutCardIsSkipped(): void
    {
        // sku = 0 — товар, которому площадка ещё не завела карточку.
        // Каталог его тоже пропускает, и имя ему привязать не к чему.
        $cards = (new OzonProductInfoListParser())->parse(
            '{"items":[{"id":1,"sku":0,"offer_id":"A","name":"Черновик"}]}',
        );

        self::assertSame([], $cards);
    }

    public function testResponseWithoutItemsIsRejected(): void
    {
        // Ошибка площадки приходит с кодом и сообщением вместо items.
        // Пустой список имён из неё делать нельзя: он выглядел бы как
        // «у товаров нет названий».
        $this->expectException(\UnexpectedValueException::class);

        (new OzonProductInfoListParser())->parse('{"code":16,"message":"Client-Id and Api-Key headers are required"}');
    }

    private function fixture(string $path): string
    {
        $body = file_get_contents($path);
        self::assertIsString($body);

        return $body;
    }
}
