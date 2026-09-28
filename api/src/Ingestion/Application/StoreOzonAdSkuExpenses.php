<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Domain\AdSkuExpenseFact;
use App\Ingestion\Domain\AdSkuExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\OzonAdSkuExpense;
use App\Ingestion\Domain\OzonAdSkuReportParser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Разбор сохранённой SKU-разбивки рекламы в факты (ADR-035 п. 3):
 * вызывается обработчиком после того, как ответ уже лежит в raw
 * (ADR-006: сначала raw, потом разбор).
 *
 * `$rawDocumentId` — id строки raw, которая реально существует: при
 * дедупликации это более ранняя строка с тем же содержимым. Момент
 * получения — от только что снятого документа, а не от той строки:
 * тот же ответ, полученный повторно, новее от дедупликации не перестаёт.
 *
 * Неудача разбора — предупреждение в журнал, а не исключение: ответ уже
 * в raw, повтор сообщения получит тот же ответ и упрётся в то же место,
 * а исключение оборвало бы обработчику куска остальные выгрузки —
 * следующие пачки и дни products/sku и заказ отчётов (ADR-006: неудача
 * разбора не отменяет загрузку). Разобрать заново можно из raw.
 */
final readonly class StoreOzonAdSkuExpenses
{
    /** Порция записи: память не растёт с объёмом отчёта (CLAUDE.md §6). */
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private OzonAdSkuReportParser $parser,
        private AdSkuExpenseFactRepository $facts,
        private LoggerInterface $logger,
    ) {
    }

    /** Асинхронный отчёт `ozon_ad_sku_report`. */
    public function fromReport(MarketplaceRawDocument $captured, Uuid $rawDocumentId): void
    {
        $this->store($captured, $rawDocumentId, fn (): array => $this->parser->parseReport($captured->body()));
    }

    /** Синхронный `products/sku`, `ozon_ad_sku_day`. */
    public function fromDay(MarketplaceRawDocument $captured, Uuid $rawDocumentId): void
    {
        $this->store($captured, $rawDocumentId, fn (): array => $this->parser->parseDay($captured->body()));
    }

    /**
     * @param \Closure(): list<OzonAdSkuExpense> $parse
     */
    private function store(MarketplaceRawDocument $captured, Uuid $rawDocumentId, \Closure $parse): void
    {
        try {
            $expenses = $parse();
        } catch (\UnexpectedValueException|\JsonException $failure) {
            $this->logger->warning('SKU-разбивка рекламы Ozon не разобрана', [
                'company_id' => $captured->companyId()->toRfc4122(),
                'marketplace_account_id' => $captured->marketplaceAccountId()->toRfc4122(),
                'report_type' => $captured->reportType(),
                'period' => $captured->period()->format('Y-m-d'),
                'raw_document_id' => $rawDocumentId->toRfc4122(),
                'reason' => $failure->getMessage(),
            ]);

            return;
        }

        foreach (array_chunk($expenses, self::CHUNK_SIZE) as $chunk) {
            $this->facts->upsertAll(array_map(
                static fn (OzonAdSkuExpense $expense): AdSkuExpenseFact => AdSkuExpenseFact::normalize(
                    $captured->companyId(),
                    $captured->marketplaceAccountId(),
                    $expense,
                    $rawDocumentId,
                    $captured->receivedAt(),
                ),
                $chunk,
            ));
        }
    }
}
