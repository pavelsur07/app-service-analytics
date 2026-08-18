<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Domain;

/**
 * Состояние отслеживания артикула (ADR-014).
 *
 * `stopped`, а не удаление строки: наблюдения цены ссылаются на артикул
 * и подключение, и удалённая запись отслеживания сделала бы уже собранную
 * историю беспризорной. Повторное «добавить в отслеживание» возвращает
 * строку в `active`, а не заводит вторую.
 */
enum TrackedSkuStatus: string
{
    case Active = 'active';
    case Stopped = 'stopped';
}
