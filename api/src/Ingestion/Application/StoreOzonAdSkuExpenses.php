<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Domain\AdSkuExpenseFact;
use App\Ingestion\Domain\AdSkuExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\OzonAdSkuExpense;
use App\Ingestion\Domain\OzonAdSkuReportParser;
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
 */
final readonly class StoreOzonAdSkuExpenses
{
    public function __construct(
        private OzonAdSkuReportParser $parser,
        private AdSkuExpenseFactRepository $facts,
    ) {
    }

    /** Асинхронный отчёт `ozon_ad_sku_report`. */
    public function fromReport(MarketplaceRawDocument $captured, Uuid $rawDocumentId): void
    {
        $this->store($captured, $rawDocumentId, $this->parser->parseReport($captured->body()));
    }

    /** Синхронный `products/sku`, `ozon_ad_sku_day`. */
    public function fromDay(MarketplaceRawDocument $captured, Uuid $rawDocumentId): void
    {
        $this->store($captured, $rawDocumentId, $this->parser->parseDay($captured->body()));
    }

    /**
     * @param list<OzonAdSkuExpense> $expenses
     */
    private function store(MarketplaceRawDocument $captured, Uuid $rawDocumentId, array $expenses): void
    {
        $this->facts->upsertAll(array_map(
            static fn (OzonAdSkuExpense $expense): AdSkuExpenseFact => AdSkuExpenseFact::normalize(
                $captured->companyId(),
                $captured->marketplaceAccountId(),
                $expense,
                $rawDocumentId,
                $captured->receivedAt(),
            ),
            $expenses,
        ));
    }
}
