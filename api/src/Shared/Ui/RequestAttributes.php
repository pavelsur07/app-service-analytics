<?php

declare(strict_types=1);

namespace App\Shared\Ui;

/**
 * Имена атрибутов запроса, которыми слои общаются между собой,
 * не импортируя друг друга.
 *
 * В Shared, а не в Identity: атрибут ставит Identity (он знает,
 * кто вошёл), а читают его контроллеры любого модуля — и импортировать
 * ради имени константы чужой модуль им нельзя, зависимости строго вниз.
 * Соглашение об имени границу не нарушает, импорт нарушил бы.
 */
final class RequestAttributes
{
    /**
     * Идентификатор вошедшего пользователя, строкой RFC 4122.
     * Ставится CompanyAccessSubscriber на company-scoped маршрутах —
     * там же, где проверяется членство, то есть везде, где он вообще
     * может понадобиться.
     */
    public const string ActorUserId = '_actor_user_id';

    /**
     * Идентификатор запроса для журнала. Ставится RequestIdListener
     * на kernel.request, читается RequestContextProcessor — чтобы строки
     * одного обращения собирались вместе (CLAUDE.md, «Наблюдаемость»).
     */
    public const string RequestId = '_request_id';

    private function __construct()
    {
    }
}
