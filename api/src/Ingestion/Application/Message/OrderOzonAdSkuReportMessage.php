<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Заказать асинхронный отчёт рекламы за `[from, to]` (`Y-m-d` по Москве)
 * (ADR-026 п. 4): SKU-отчёт — по пачке кампаний не больше десяти, отчёт
 * заказов «Оплаты за заказ» — по всей организации, без кампаний. Имя
 * класса осталось от первого вида: сообщения уже ходят по очереди.
 */
final readonly class OrderOzonAdSkuReportMessage
{
    /**
     * @param list<string> $campaignIds
     */
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $from,
        public string $to,
        public array $campaignIds,
        /** Номер попытки заказа, с единицы: растёт на отказах лимита (429). */
        public int $attempt = 1,
        /**
         * Момент первого отказа лимита (ATOM); `null` — отказов ещё не было.
         * Потолок повторов меряется от него по времени, а не числом попыток.
         */
        public ?string $refusedSince = null,
        /** Вид отчёта (`OzonAdReportKind`); `null` — SKU-отчёт. */
        public ?string $kind = null,
    ) {
    }
}
