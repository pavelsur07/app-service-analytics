<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

/**
 * Имена атрибутов запроса, которыми ExtensionTokenHandler передаёт
 * результат проверки токена дальше по конвейеру (ADR-010).
 *
 * Отдельный класс, а не константы на самом обработчике: обработчик живёт
 * в узком слое Deptrac (IdentityExtensionTokenHandler), закрытом от
 * IdentityUi — иначе контроллер, импортировав константу, получил бы
 * доступ и к межарендаторному поиску. Имена — контракт двух сторон,
 * поиск по хэшу — нет.
 *
 * Не `companyId`: в маршрутах расширения с {companyId} в пути (появятся
 * вместе с чтением данных компании) нужен будет сверяющий шаг — «компания
 * токена ≠ компания в пути → 403», а не молчаливая перезапись. Разные
 * имена оставляют этот шов видимым и не подменяют вход, который проверяет
 * CompanyAccessSubscriber.
 */
final class ExtensionTokenRequestAttributes
{
    public const string TOKEN_ID = 'extensionTokenId';
    public const string COMPANY_ID = 'extensionTokenCompanyId';

    private function __construct()
    {
    }
}
