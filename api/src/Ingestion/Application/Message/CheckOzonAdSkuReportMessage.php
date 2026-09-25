<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

use App\Ingestion\Domain\OzonAdReportKind;

/**
 * Проверить заказанный SKU-отчёт и, если готов, скачать его в raw
 * (ADR-026 п. 4). `attempt` — номер проверки, с единицы.
 */
final readonly class CheckOzonAdSkuReportMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $from,
        public string $uuid,
        public int $attempt,
        /** Вид отчёта (`OzonAdReportKind`); `null` — SKU-отчёт. */
        public ?string $kind = null,
    ) {
    }

    /**
     * Вид отчёта. Чтение только через этот метод: сообщение, стоявшее
     * в очереди до появления поля, восстанавливается без конструктора,
     * и свойство у него не инициализировано, а не `null` — `??` читает
     * такое свойство без ошибки.
     */
    public function reportKind(): string
    {
        return OzonAdReportKind::of($this->kind ?? null);
    }
}
