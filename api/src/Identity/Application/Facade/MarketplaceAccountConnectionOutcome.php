<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

/**
 * Занятый кабинет — обычный ответ клиенту, а не сбой: исход, а не
 * исключение. Иначе ошибка человека приезжала бы в трекер как наша.
 */
enum MarketplaceAccountConnectionOutcome
{
    case Connected;
    /** Кабинет уже подключён — к этой компании или к другой (ADR-021). */
    case AlreadyConnected;
}
