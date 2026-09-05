<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Тот же набор исходов, что у доменного DiscardAccountOutcome, но
 * в границе модуля — тот же приём, что у CredentialsReplacementOutcome
 * рядом: Ingestion видит только Facade, общего слоя у Domain двух
 * модулей нет.
 */
enum DiscardConnectionOutcome
{
    case Discarded;
    case NotFound;
    /** У подключения есть то, что закрывать — решает вызывающий колбэк. */
    case InUse;
}
