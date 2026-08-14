<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

/**
 * Ответ на успешную замену ключей. Самих ключей в нём нет и быть
 * не может — клиент только что их прислал, а хранить их эхо в ответе
 * значит оставить секрет в истории запросов браузера.
 */
final readonly class ReplacedCredentialsResponse
{
    public function __construct(
        public string $id,
        public string $state,
    ) {
    }
}
