<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Message;

/**
 * Загрузка списка рекламных кампаний подключения в raw
 * (ADR-026 п. 4: весь список на каждом тике).
 */
final readonly class FetchOzonAdCampaignsMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplaceAccountId,
        /**
         * День снимка, `Y-m-d` по Москве, — `period` raw-документа.
         * Задаёт отправитель, а не обработчик: повтор сообщения после
         * полуночи обязан попасть в тот же документ (CLAUDE.md §4).
         */
        public string $day,
    ) {
    }
}
