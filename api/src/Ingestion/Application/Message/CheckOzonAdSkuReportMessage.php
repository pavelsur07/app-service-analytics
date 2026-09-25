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
        /**
         * Конец периода отчёта, Y-m-d. `null` у сообщений, стоявших
         * в очереди до появления поля, — читать через `periodTo()`.
         */
        public ?string $to = null,
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

    /**
     * Конец периода отчёта. У сообщений без поля — начало плюс 29 дней:
     * кусок не длиннее 30 дней, точнее без поля не узнать.
     */
    public function periodTo(): string
    {
        $to = $this->to ?? null;
        if (null !== $to) {
            return $to;
        }

        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->from);

        return false === $from ? $this->from : $from->modify('+29 days')->format('Y-m-d');
    }
}
