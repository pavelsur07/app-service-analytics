<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Очередь истории (`async_backfill`, docs/task/ingestion-queue-isolation.md).
 *
 * Первичные загрузки, консольные доборы и глубокий рескан ставятся туда,
 * а не в очередь тика: иначе годовая загрузка одного кабинета встаёт
 * перед свежими сообщениями тика всех остальных. Транспорт выбирает
 * отправитель: одно и то же сообщение (кусок рекламы, день расходов)
 * бывает и тиком, и историей, и маршрут по классу их не различит.
 */
final class IngestionBackfill
{
    public const string TRANSPORT = 'async_backfill';

    private function __construct()
    {
    }

    /**
     * @return list<TransportNamesStamp>
     */
    public static function stamps(): array
    {
        return [new TransportNamesStamp([self::TRANSPORT])];
    }
}
