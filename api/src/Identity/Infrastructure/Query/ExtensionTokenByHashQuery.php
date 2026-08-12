<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Межарендаторный поиск токена расширения по хэшу предъявленного секрета
 * (CLAUDE.md §1, «Исключение — межарендаторное чтение...»; тот же приём,
 * что у ActiveOzonAccountsQuery — DBAL-запрос вне репозитория, не метод
 * ExtensionTokenRepository: интерфейс репозитория остаётся без исключений,
 * каждый его метод по-прежнему требует companyId).
 *
 * Межарендаторность видна из имени: поиск идёт по секрету, до того как
 * компания известна, — компанию как раз и определяет найденная строка.
 *
 * Недостижим из HTTP-контроллера: узкий слой Deptrac (api/deptrac.php,
 * IdentityExtensionTokenQuery) выведен из широкого IdentityInfrastructure
 * через mustNot и выдан только IdentityExtensionTokenHandler.
 * IdentityUi его не видит.
 *
 * Отдаёт готовую строку, а не QueryBuilder: правило §5 про QueryBuilder
 * относится к спискам, здесь точечное чтение одной строки — как
 * у CompanyMemberRepository::existsForUserAndCompany.
 *
 * Отозванный и истёкший отсеиваются в SQL: наружу обе причины
 * неотличимы от «нет такого токена», и это намеренно — предъявителю
 * не сообщается, существовал ли токен вообще.
 */
final readonly class ExtensionTokenByHashQuery
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findUsable(string $tokenHash, \DateTimeImmutable $now): ?ExtensionTokenRow
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT t.id, t.company_id, t.user_id, u.email
                FROM extension_token t
                INNER JOIN "user" u ON u.id = t.user_id
                WHERE t.token_hash = :tokenHash
                  AND t.revoked_at IS NULL
                  AND t.expires_at > :now
                SQL,
            ['tokenHash' => $tokenHash, 'now' => $now->format('Y-m-d H:i:s')],
        );

        if (false === $row) {
            return null;
        }

        return new ExtensionTokenRow(
            id: self::stringValue($row['id']),
            companyId: self::stringValue($row['company_id']),
            userId: self::stringValue($row['user_id']),
            userEmail: self::stringValue($row['email']),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in an extension token row.');
        }

        return $value;
    }
}
