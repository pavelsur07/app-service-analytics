<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\ValueObject\AdminRole;
use App\Tests\Support\Builder\AdministratorBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Роль администратора — настоящая Symfony-роль (ADR-017), в отличие
 * от CompanyMemberRole. Проверяется соответствие «роль → имя роли»
 * и то, что вышестоящую сущность не доклеивает: это работа
 * role_hierarchy, и описана она должна быть в одном месте.
 */
final class AdministratorTest extends TestCase
{
    public function testAdminGetsOnlyAdminRole(): void
    {
        $administrator = AdministratorBuilder::anAdministrator()->withRole(AdminRole::Admin)->build();

        self::assertSame(['ROLE_ADMIN'], $administrator->getRoles());
    }

    public function testSuperAdminGetsOnlySuperAdminRole(): void
    {
        $administrator = AdministratorBuilder::anAdministrator()->withRole(AdminRole::SuperAdmin)->build();

        // Не ['ROLE_SUPER_ADMIN', 'ROLE_ADMIN']: покрытие нижней роли
        // даёт role_hierarchy в security.yaml. Продублируй список здесь —
        // и иерархия окажется описана в двух местах, которые разъедутся.
        self::assertSame(['ROLE_SUPER_ADMIN'], $administrator->getRoles());
    }

    public function testEmailIsNormalizedOnCreation(): void
    {
        $administrator = AdministratorBuilder::anAdministrator()->withEmail('  OPS@Example.COM ')->build();

        self::assertSame('ops@example.com', $administrator->email());
        self::assertSame('ops@example.com', $administrator->getUserIdentifier());
    }

    public function testBootstrapAdministratorHasNoAuthor(): void
    {
        $administrator = AdministratorBuilder::anAdministrator()->withRole(AdminRole::SuperAdmin)->build();

        self::assertNull($administrator->createdByAdminId());
    }
}
