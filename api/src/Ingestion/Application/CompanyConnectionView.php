<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Подключение вместе со свежестью его выгрузок — то, что видит экран.
 *
 * $lastLoadedAt — тип отчёта => момент последней загрузки. Пустой массив
 * означает, что по подключению не приходило ещё ничего: так выглядит
 * и только что заведённое подключение, и сломанное с первого дня.
 * Различать их — дело состояния, а не этого поля.
 */
final readonly class CompanyConnectionView
{
    /**
     * @param array<string, string> $lastLoadedAt
     */
    public function __construct(
        public string $id,
        public string $marketplace,
        public string $externalShopId,
        public string $state,
        public string $createdAt,
        public array $lastLoadedAt,
    ) {
    }
}
