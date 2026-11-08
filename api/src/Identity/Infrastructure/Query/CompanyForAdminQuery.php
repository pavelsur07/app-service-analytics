<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use App\Identity\Domain\AuditAction;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Чтение выбранной компании системным администратором без членства
 * (CLAUDE.md §1, системный контур; ADR-017, ADR-018).
 * Узкий слой IdentityAdminCompanyQuery доступен только GetClientAccountController.
 * Данные дочерних списков всегда ограничены companyId; секреты и значения
 * audit previous_value/new_value не выбираются даже для последующей фильтрации.
 */
final readonly class CompanyForAdminQuery
{
    public const int MEMBERS_LIMIT = 50;
    public const int AUDIT_LIMIT = 20;

    public function __construct(private Connection $connection)
    {
    }

    public function build(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('c.id', 'c.name', 'c.status', 'c.created_at')
            ->addSelect(<<<'SQL'
                EXISTS (
                    SELECT 1 FROM company_member cm
                    JOIN "user" u ON u.id = cm.user_id
                    WHERE cm.company_id = c.id AND u.email_confirmed_at IS NOT NULL
                ) AS has_confirmed_user
                SQL)
            ->from('company', 'c')
            ->where('c.id = :companyId')
            ->setParameter('companyId', $companyId)
            ->setMaxResults(1);
    }

    public function members(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('u.id', 'u.email', 'cm.role', 'cm.created_at AS joined_at', 'u.email_confirmed_at')
            ->from('company_member', 'cm')
            ->innerJoin('cm', '"user"', 'u', 'u.id = cm.user_id')
            ->where('cm.company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('cm.created_at', 'ASC')->addOrderBy('cm.user_id', 'ASC')
            ->setMaxResults(self::MEMBERS_LIMIT + 1);
    }

    /** Только последние события поддержки, не полный журнал аудита. */
    public function recentAudit(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('a.id', 'a.action', 'a.occurred_at')
            ->addSelect("CASE WHEN a.actor_admin_id IS NULL THEN 'user' ELSE 'admin' END AS actor_type")
            ->addSelect('COALESCE(a.actor_admin_id, a.actor_user_id) AS actor_id')
            ->addSelect('COALESCE(ad.email, u.email) AS actor_email')
            ->from('audit_record', 'a')
            ->leftJoin('a', 'administrator', 'ad', 'ad.id = a.actor_admin_id')
            ->leftJoin('a', '"user"', 'u', 'u.id = a.actor_user_id')
            ->where('a.company_id = :companyId')
            ->andWhere('a.action IN (:actions)')
            ->setParameter('companyId', $companyId)
            ->setParameter('actions', [
                AuditAction::CompanyRegistered, AuditAction::CompanyBlocked, AuditAction::CompanyActivated,
                AuditAction::MarketplaceAccountConnected, AuditAction::MarketplaceAccountDiscarded,
                AuditAction::MarketplaceCredentialsReplaced, AuditAction::MarketplaceAdvertisingCredentialsReplaced,
                AuditAction::UserEmailConfirmed,
            ], ArrayParameterType::STRING)
            ->orderBy('a.occurred_at', 'DESC')->addOrderBy('a.id', 'DESC')
            ->setMaxResults(self::AUDIT_LIMIT + 1);
    }

    /** @param array<string, mixed> $row */
    public static function mapCompany(array $row): AdminCompanyRow
    {
        return new AdminCompanyRow(
            id: self::stringValue($row['id']), name: self::stringValue($row['name']),
            status: self::stringValue($row['status']), createdAt: self::dateValue($row['created_at']),
            hasConfirmedUser: \in_array($row['has_confirmed_user'], [true, 1, '1', 't', 'true'], true),
        );
    }

    /** @param array<string, mixed> $row */
    public static function mapMember(array $row): AdminCompanyMemberRow
    {
        return new AdminCompanyMemberRow(
            id: self::stringValue($row['id']), email: self::stringValue($row['email']),
            role: self::stringValue($row['role']), joinedAt: self::dateValue($row['joined_at']),
            emailConfirmedAt: null === $row['email_confirmed_at'] ? null : self::dateValue($row['email_confirmed_at']),
        );
    }

    /** @param array<string, mixed> $row */
    public static function mapAudit(array $row): AdminCompanyAuditRow
    {
        return new AdminCompanyAuditRow(
            id: self::stringValue($row['id']), action: self::stringValue($row['action']),
            actorType: self::stringValue($row['actor_type']), actorId: self::stringValue($row['actor_id']),
            actorEmail: null === $row['actor_email'] ? null : self::stringValue($row['actor_email']),
            occurredAt: self::dateValue($row['occurred_at']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string in an admin company row.');
        }

        return $value;
    }

    private static function dateValue(mixed $value): string
    {
        return (new \DateTimeImmutable(self::stringValue($value), new \DateTimeZone('UTC')))->format(\DATE_ATOM);
    }
}
