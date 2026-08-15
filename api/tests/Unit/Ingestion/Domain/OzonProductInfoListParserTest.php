<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Domain;

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

    public function testParsesNamesFromRealCabinetResponse(): void
    {
        $names = (new OzonProductInfoListParser())->parse($this->fixture(self::FIXTURE));

        self::assertCount(62, $names);
        foreach ($names as $sku => $name) {
            self::assertNotSame('', $sku);
            self::assertNotSame('', $name);
        }
    }

    public function testEveryCatalogSkuGetsAName(): void
    {
        // Ради этого и снимались обе фикстуры одним днём: склейка идёт
        // по sku, а не по product_id, и если бы ответы расходились хоть
        // на одну карточку, половина каталога осталась бы безымянной.
        $names = (new OzonProductInfoListParser())->parse($this->fixture(self::FIXTURE));

        /** @var array{result: array{items: list<array{sku: int}>}} $catalog */
        $catalog = json_decode($this->fixture(self::CATALOG_FIXTURE), true, flags: \JSON_THROW_ON_ERROR);

        foreach ($catalog['result']['items'] as $item) {
            self::assertArrayHasKey((string) $item['sku'], $names);
        }
    }

    public function testEntryWithoutNameIsSkippedRatherThanStoredEmpty(): void
    {
        // Пустое имя от площадки не приходит — имя у карточки
        // обязательное. Придёт неполный ответ — писатель оставит
        // известное имя, а не затрёт его пустотой, и для этого его
        // здесь не должно быть вовсе.
        $names = (new OzonProductInfoListParser())->parse(
            '{"items":[{"id":1,"sku":220280923,"offer_id":"A"},{"id":2,"sku":220280924,"offer_id":"B","name":"Топ"}]}',
        );

        self::assertSame(['220280924' => 'Топ'], $names);
    }

    public function testProductWithoutCardIsSkipped(): void
    {
        // sku = 0 — товар, которому площадка ещё не завела карточку.
        // Каталог его тоже пропускает, и имя ему привязать не к чему.
        $names = (new OzonProductInfoListParser())->parse(
            '{"items":[{"id":1,"sku":0,"offer_id":"A","name":"Черновик"}]}',
        );

        self::assertSame([], $names);
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
