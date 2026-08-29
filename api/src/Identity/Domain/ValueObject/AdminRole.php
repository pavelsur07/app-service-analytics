<?php

declare(strict_types=1);

namespace App\Identity\Domain\ValueObject;

/**
 * Роль внутри контура администраторов (ADR-017). Две и только две:
 * `SuperAdmin` заводит администраторов, `Admin` управляет клиентскими
 * аккаунтами. Обе могут второе, первое — только `SuperAdmin`.
 *
 * Не путать с CompanyMemberRole: та живёт на членстве в компании
 * и к системному контуру отношения не имеет.
 */
enum AdminRole: string
{
    case Admin = 'admin';
    case SuperAdmin = 'super_admin';

    /**
     * Имя роли для Symfony. Соответствие «одна роль — одно имя», без
     * перечисления вышестоящих: `ROLE_SUPER_ADMIN` покрывает
     * `ROLE_ADMIN` через role_hierarchy (ADR-017), а не через список
     * здесь. Иначе иерархия описана в двух местах и разъедется.
     *
     * @return non-empty-string
     */
    public function securityRole(): string
    {
        return match ($this) {
            self::Admin => 'ROLE_ADMIN',
            self::SuperAdmin => 'ROLE_SUPER_ADMIN',
        };
    }
}
