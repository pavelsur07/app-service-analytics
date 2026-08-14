<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Расходы одного подключения за один день начисления.
 *
 * День, а не период: метод площадки принимает ровно одну дату
 * (ADR-012), и дробить окно на дни всё равно пришлось бы — лучше явно,
 * одним сообщением на день, чем циклом внутри обработчика: повтор
 * одного упавшего дня не тянет за собой остальные.
 */
final readonly class FetchOzonExpensesMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        public string $accrualDate,
    ) {
    }
}
