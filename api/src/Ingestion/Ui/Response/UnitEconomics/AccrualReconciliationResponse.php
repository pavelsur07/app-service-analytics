<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response\UnitEconomics;

/**
 * Сверка с кабинетом за месяц: начисления Ozon по группам отчёта
 * «Начисления» и итог к начислению (ADR-036). Деньги — минорные единицы,
 * форматирование и никакой арифметики — у клиента (§10).
 */
final readonly class AccrualReconciliationResponse
{
    /**
     * @param list<AccrualReconciliationGroupResponse> $groups
     */
    public function __construct(
        /** Месяц, Y-m. */
        public string $month,
        /** Первый день периода, Y-m-d. */
        public string $from,
        /** Последний день периода, Y-m-d: конец месяца или сегодня для текущего. */
        public string $to,
        /** null — за период нет ни одного начисления. */
        public ?string $currency,
        public array $groups,
        /** Итого к начислению: сумма всех групп. */
        public int $totalMinor,
        /** Дни периода, за которые выгрузка начислений не проходила. */
        public int $daysWithoutAccruals,
    ) {
    }
}
