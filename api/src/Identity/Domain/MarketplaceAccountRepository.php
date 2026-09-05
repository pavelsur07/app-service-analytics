<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface MarketplaceAccountRepository
{
    public function add(MarketplaceAccount $account): void;

    /**
     * $companyId первым параметром (CLAUDE.md §1): поиск подключения
     * по одному лишь id запрещён, изоляция арендаторов проверяется
     * в каждом методе чтения, а не JOIN'ом или доверием к вызывающему.
     * Без исключений — межарендаторное перечисление для планировщика
     * живёт вне этого интерфейса, в ActiveOzonAccountsQuery (DBAL,
     * тот же приём, что у UserCompaniesQuery).
     */
    public function get(string $companyId, Uuid $id): ?MarketplaceAccount;

    /**
     * Перевод в broken (ADR-007) с условием «было active» внутри самого
     * UPDATE. Возвращает true тому вызову, который состояние действительно
     * поменял, — по нему и решается, отправлять ли письмо клиенту.
     *
     * Не «прочитать, проверить, записать»: планировщик ставит на одно
     * подключение две задачи разом (продажи и каталог), обе получат отказ
     * авторизации одновременно, и проверка перед записью прошла бы у обеих
     * (CLAUDE.md §4). Клиент получил бы два письма об одном событии.
     */
    public function markBrokenIfActive(string $companyId, Uuid $id): bool;

    /**
     * Подключение кабинета вместе с записью в журнал, одной транзакцией:
     * строка подключения без аудит-записи — строка, о происхождении которой
     * спросить будет не у кого (ADR-011).
     *
     * Возвращает false, когда кабинет уже занят. Проверки перед вставкой
     * нет намеренно: между ней и вставкой два параллельных запроса прошли
     * бы её оба (CLAUDE.md §4), поэтому уникальность держит индекс,
     * а конфликт перехватывается здесь.
     *
     * `wrapInTransaction` закрывает EntityManager при любом откате —
     * в том числе на пути `false`, не только на пробросе. После вызова,
     * вернувшего false, инжектированный EntityManager закрыт до конца
     * запроса: следующий `persist()`/`flush()` в нём упадёт `EntityManagerClosed`
     * вместо доменной ошибки, а не отработает как ни в чём не бывало.
     */
    public function tryConnect(MarketplaceAccount $account, AuditRecord $trail): bool;
}
