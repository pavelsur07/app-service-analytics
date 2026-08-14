<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Три исхода замены ключей, которые контроллер обязан различать
 * по-разному: площадка отвергла ключ (клиенту — «проверьте ключ»),
 * подключения нет у этой компании (404), ключ принят и сохранён.
 */
enum ReplaceCredentialsResult
{
    case Replaced;
    case Rejected;
    case NotFound;
}
