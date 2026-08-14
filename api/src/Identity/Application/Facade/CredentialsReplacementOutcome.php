<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Тот же набор исходов, что у доменного ReplaceCredentialsOutcome, но
 * в границе модуля: Ingestion видит только Facade, а Identity Application
 * не видит Facade — общего слоя у них нет, и общий тип пришлось бы класть
 * в Shared, где предметной логике не место.
 *
 * Приём тот же, что у CompanyConnectionRow → CompanyConnection: результат
 * внутреннего слоя перекладывается в тип границы. Дублирование здесь —
 * не издержка, а то, ради чего граница и существует: набор исходов
 * снаружи меняется тогда, когда мы этого хотим, а не тогда, когда внутри
 * появился новый случай.
 */
enum CredentialsReplacementOutcome
{
    case Replaced;
    case NotFound;
    /** Отзыв необратим (ADR-011) — заменой ключа подключение не оживить. */
    case Revoked;
    /** Данные изменил кто-то ещё (ADR-008) — перечитать и повторить. */
    case VersionConflict;
}
