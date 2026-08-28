<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Строка отчёта по артикулу за период (CLAUDE.md §5): продажи и итог
 * расходов уже сведены запросом. Денежные величины — минорные единицы
 * плюс код валюты (ADR-004).
 */
final readonly class UnitEconomicsSkuRow
{
    public function __construct(
        public string $marketplaceSku,
        public string $currency,
        public int $deliveredQuantity,
        public int $deliveredAmountMinor,
        public int $commissionAmountMinor,
        public int $orderedQuantity,
        public int $expensesTotalMinor,
        /** Себестоимость проданного, отрицательная — как и прочие вычеты. */
        public int $costTotalMinor,
        /** Сколько проданных штук пришлось на дни без заданной цены. */
        public int $quantityWithoutCost,
        /** Когда себестоимость, применённую к этому периоду, правили. */
        public ?string $costCorrectedAt,
        /**
         * Маржа, посчитанная запросом — только чтобы по ней сортировать
         * и строить курсор. Цифру для клиента считает Money в сценарии;
         * совпадение двух источников закреплено тестом.
         */
        public int $marginMinor,
        /** Название карточки. null, пока каталог не подтянулся. */
        public ?string $name,
        /** Артикул селлера — то, чем товар зовут в кабинете. */
        public ?string $offerId,
        /** Адрес главного фото на CDN площадки. Размер подставит фронтенд. */
        public ?string $photoUrl,
    ) {
    }
}
