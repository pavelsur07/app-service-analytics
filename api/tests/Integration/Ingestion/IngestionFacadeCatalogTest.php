<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Application\Facade\CatalogSku;
use App\Ingestion\Application\Facade\IngestionFacade;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Каталог подключения для планирования продаж (ADR-040 п. 4): поиск
 * страницей и проверка набора — и изоляция компаний в обоих.
 */
final class IngestionFacadeCatalogTest extends KernelTestCase
{
    public function testPagesThroughAccountCatalogWithoutGapsOrRepeats(): void
    {
        self::bootKernel();
        [$companyId, $accountId] = [Uuid::v7(), Uuid::v7()];
        $this->catalog($companyId, $accountId, ['105', '101', '104', '102', '103']);

        $first = $this->facade()->searchAccountCatalogSkus($companyId->toRfc4122(), $accountId->toRfc4122(), '', null, 2);
        $second = $this->facade()->searchAccountCatalogSkus($companyId->toRfc4122(), $accountId->toRfc4122(), '', $first->nextCursor, 2);
        $third = $this->facade()->searchAccountCatalogSkus($companyId->toRfc4122(), $accountId->toRfc4122(), '', $second->nextCursor, 2);

        self::assertSame(['101', '102'], $this->skus($first->items));
        self::assertSame(['103', '104'], $this->skus($second->items));
        self::assertSame(['105'], $this->skus($third->items));
        self::assertNull($third->nextCursor);
    }

    public function testSearchesBySkuOfferIdAndNameLiterally(): void
    {
        self::bootKernel();
        [$companyId, $accountId] = [Uuid::v7(), Uuid::v7()];
        $this->catalog($companyId, $accountId, [
            ['308520421', 'KR-100', 'Крем дневной'],
            ['308520422', 'KR_200', 'Крем ночной 50%'],
            ['308520423', 'KR-50', 'Крем 50 мл'],
            ['999000111', 'SH-300', 'Шампунь'],
            ['999000112', 'TB-1', 'Tube!box'],
            ['999000113', 'BT-1', 'bottle'],
        ]);
        $search = fn (string $text): array => $this->skus(
            $this->facade()->searchAccountCatalogSkus($companyId->toRfc4122(), $accountId->toRfc4122(), $text, null, 50)->items,
        );

        self::assertSame(['999000111'], $search('000111'));
        self::assertSame(['308520421'], $search('kr-1'));
        self::assertSame(['308520421', '308520422', '308520423'], $search('крем'));
        // Каждая проверка различает буквальный символ и шаблон: без
        // экранирования `%` нашёлся бы и «Крем 50 мл», `_` — «KR-100»
        // и «KR-50», а `!b` превратился бы в «b» и нашёл «bottle».
        self::assertSame(['308520422'], $search('50%'));
        self::assertSame(['308520422'], $search('KR_'));
        self::assertSame(['999000112'], $search('!b'));
    }

    public function testAnotherCompanyGetsNothingFromTheSameAccountId(): void
    {
        self::bootKernel();
        [$companyId, $accountId] = [Uuid::v7(), Uuid::v7()];
        $this->catalog($companyId, $accountId, ['101', '102']);
        $stranger = Uuid::v7()->toRfc4122();

        self::assertSame([], $this->facade()->searchAccountCatalogSkus($stranger, $accountId->toRfc4122(), '', null, 50)->items);
        self::assertSame([], $this->facade()->presentAccountCatalogSkus($stranger, $accountId->toRfc4122(), ['101', '102']));
    }

    public function testOtherAccountOfTheSameCompanyIsNotMixedIn(): void
    {
        self::bootKernel();
        [$companyId, $accountId, $otherAccountId] = [Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $this->catalog($companyId, $accountId, ['101']);
        $this->catalog($companyId, $otherAccountId, ['202']);

        self::assertSame(['101'], $this->skus(
            $this->facade()->searchAccountCatalogSkus($companyId->toRfc4122(), $accountId->toRfc4122(), '', null, 50)->items,
        ));
        self::assertSame(['101'], $this->facade()->presentAccountCatalogSkus($companyId->toRfc4122(), $accountId->toRfc4122(), ['101', '202']));
    }

    public function testPresenceReturnsExactlyTheIntersection(): void
    {
        self::bootKernel();
        [$companyId, $accountId] = [Uuid::v7(), Uuid::v7()];
        $this->catalog($companyId, $accountId, ['101', '102', '103']);

        self::assertSame(
            ['101', '103'],
            $this->facade()->presentAccountCatalogSkus($companyId->toRfc4122(), $accountId->toRfc4122(), ['103', '999', '101', '101']),
        );
        self::assertSame([], $this->facade()->presentAccountCatalogSkus($companyId->toRfc4122(), $accountId->toRfc4122(), []));
    }

    public function testRefusesPageOverTheLimit(): void
    {
        self::bootKernel();

        $this->expectException(\InvalidArgumentException::class);

        $this->facade()->searchAccountCatalogSkus(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), '', null, IngestionFacade::CatalogPageMaxLimit + 1);
    }

    /**
     * @param list<string|array{string, string, string}> $entries артикул или [артикул, артикул продавца, название]
     */
    private function catalog(Uuid $companyId, Uuid $accountId, array $entries): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = self::getContainer()->get(MarketplaceListingRepository::class);
        $listings->replaceForAccount(
            $companyId->toRfc4122(),
            $accountId,
            array_map(
                static function (string|array $entry) use ($companyId, $accountId) {
                    [$sku, $offerId, $name] = \is_array($entry) ? $entry : [$entry, null, null];

                    return MarketplaceListingBuilder::aMarketplaceListing()
                        ->withCompanyId($companyId)
                        ->withMarketplaceAccountId($accountId)
                        ->withMarketplaceSku($sku)
                        ->withOfferId($offerId)
                        ->withName($name)
                        ->build();
                },
                $entries,
            ),
        );
    }

    /**
     * @param list<CatalogSku> $items
     *
     * @return list<string>
     */
    private function skus(array $items): array
    {
        return array_map(static fn (CatalogSku $sku): string => $sku->marketplaceSku, $items);
    }

    private function facade(): IngestionFacade
    {
        /** @var IngestionFacade $facade */
        $facade = self::getContainer()->get(IngestionFacade::class);

        return $facade;
    }
}
