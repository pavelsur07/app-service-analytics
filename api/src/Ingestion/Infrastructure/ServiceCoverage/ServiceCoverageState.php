<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\ServiceCoverage;

/**
 * Исход обращения к `GET /internal/coverage` сервиса (контракт
 * service-coverage v1). «Сервис не ответил» и «сервис выключен» —
 * разные состояния, а не пустой ответ: пустая сводка читалась бы
 * как «ничего не загружено».
 */
enum ServiceCoverageState: string
{
    case Ok = 'ok';
    /** Адрес или токен сервиса в окружении api не заданы. */
    case Disabled = 'disabled';
    /** 503 `cabinets_not_ready`: сразу после старта снимка подключений ещё нет. */
    case NotReady = 'not_ready';
    /** Сеть, иной статус, ответ вне контракта. */
    case Unavailable = 'unavailable';
}
