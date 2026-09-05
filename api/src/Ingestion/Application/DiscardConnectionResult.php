<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

/**
 * Исходы удаления подключения, которые контроллер обязан различать
 * по-разному: у каждого свой код ответа.
 */
enum DiscardConnectionResult
{
    case Discarded;
    case NotFound;
    /** У подключения есть загруженные документы — удалить нельзя. */
    case HasHistory;
}
