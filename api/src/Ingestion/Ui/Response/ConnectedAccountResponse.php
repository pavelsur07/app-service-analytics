<?php

declare(strict_types=1);

namespace App\Ingestion\Ui\Response;

use OpenApi\Attributes as OA;

/**
 * Ответ на успешное подключение. Учётных данных здесь нет и быть
 * не может: эхо секрета оставило бы его в истории запросов браузера
 * и в логах любого прокси на пути.
 */
final readonly class ConnectedAccountResponse
{
    public function __construct(
        #[OA\Property(description: 'Идентификатор подключения')]
        public string $id,
        #[OA\Property(description: 'Название магазина')]
        public string $name,
        #[OA\Property(description: 'Состояние подключения')]
        public string $state,
    ) {
    }
}
