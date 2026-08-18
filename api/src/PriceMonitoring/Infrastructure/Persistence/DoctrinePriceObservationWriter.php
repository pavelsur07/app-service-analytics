<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Infrastructure\Persistence;

use App\PriceMonitoring\Domain\PriceObservation;
use App\PriceMonitoring\Domain\PriceObservationRepository;
use Doctrine\DBAL\Connection;

/**
 * DBAL, не ORM (CLAUDE.md §6: факт-таблицы ORM никогда не пишет).
 *
 * `ON CONFLICT DO NOTHING` по естественному ключу — вся идемпотентность
 * приёма. Проверки «а нет ли уже такого снимка» нет и быть не должно:
 * между ней и вставкой два параллельных запроса прошли бы её оба (§4).
 *
 * Обновления при конфликте тоже нет, в отличие от `sales_fact`: площадка
 * перевыпускает отчёты задним числом, а снимок цены — то, что видели
 * в конкретный момент, и переписать его нечем.
 *
 * По строке на запрос, не пачкой: наблюдения приходят по одному, каждое
 * своим HTTP-запросом от расширения. Пакетная вставка здесь была бы
 * сложением там, где складывать нечего.
 */
final readonly class DoctrinePriceObservationWriter implements PriceObservationRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function record(PriceObservation $observation): bool
    {
        $affected = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO price_observation
                    (company_id, marketplace_account_id, marketplace_sku, observed_at,
                     displayed_price_minor, seller_price_minor, currency,
                     source, captured_by_user_id, extension_version, received_at)
                VALUES
                    (:companyId, :marketplaceAccountId, :marketplaceSku, :observedAt,
                     :displayedPriceMinor, :sellerPriceMinor, :currency,
                     :source, :capturedByUserId, :extensionVersion, :receivedAt)
                ON CONFLICT (company_id, marketplace_account_id, marketplace_sku, observed_at)
                DO NOTHING
                SQL,
            [
                'companyId' => $observation->companyId()->toRfc4122(),
                'marketplaceAccountId' => $observation->marketplaceAccountId()->toRfc4122(),
                'marketplaceSku' => $observation->marketplaceSku(),
                'observedAt' => $observation->observedAt()->format('Y-m-d H:i:s'),
                'displayedPriceMinor' => $observation->displayedPrice()->minorAmount(),
                'sellerPriceMinor' => $observation->sellerPrice()->minorAmount(),
                'currency' => $observation->displayedPrice()->currency(),
                'source' => $observation->source(),
                'capturedByUserId' => $observation->capturedByUserId()->toRfc4122(),
                'extensionVersion' => $observation->extensionVersion(),
                'receivedAt' => $observation->receivedAt()->format('Y-m-d H:i:s'),
            ],
        );

        return $affected > 0;
    }
}
