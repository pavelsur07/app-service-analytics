<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Адреса участников компании — кому уходит письмо о сломанном подключении
 * (ADR-007). DBAL, без гидрации сущностей (CLAUDE.md §5).
 *
 * companyId первым параметром и в самом SQL: это обычное company-scoped
 * чтение, никаких исключений §1 здесь нет и не нужно.
 *
 * Всем участникам, а не одному «владельцу»: роли в ADR-002 нет, и выбирать
 * получателя было бы гаданием. Сломанное подключение — событие компании,
 * а не личное дело того, кто его когда-то завёл.
 */
final readonly class CompanyMemberEmailsQuery
{
    /**
     * Потолок против рассылки, а не бизнес-ограничение: компаний
     * с полусотней участников на этой стадии не бывает, а список
     * без предела не отдаётся никогда (CLAUDE.md §5).
     */
    public const int MAX_RESULTS = 50;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function build(string $companyId): QueryBuilder
    {
        return $this->connection->createQueryBuilder()
            ->select('u.email')
            ->from('company_member', 'm')
            ->innerJoin('m', '"user"', 'u', 'u.id = m.user_id')
            ->where('m.company_id = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('u.email', 'ASC')
            ->setMaxResults(self::MAX_RESULTS);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): string
    {
        $email = $row['email'];
        if (!\is_string($email)) {
            throw new \UnexpectedValueException('Expected a string email in a company member row.');
        }

        return $email;
    }
}
