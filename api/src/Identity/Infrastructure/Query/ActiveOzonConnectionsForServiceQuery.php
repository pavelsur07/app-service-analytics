<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Межарендаторное чтение сервисного контура (CLAUDE.md §1, четвёртый
 * случай; ADR-043 п. 4): активные подключения Ozon всех компаний вместе
 * с зашифрованными ключами — для выдачи сервисам вне монолита.
 *
 * Отдельный DBAL-запрос, а не метод MarketplaceAccountRepository:
 * интерфейс репозитория остаётся без исключений. Deptrac держит класс
 * в узком слое IdentityServiceConnectionsQuery, доступ к нему — только
 * у MarketplaceConnectionsController (узкий слой
 * IdentityServiceConnectionsUi): ни Ui продавца, ни admin, ни
 * планировщик этот запрос не видят.
 *
 * Keyset по id (CLAUDE.md §5): страница — строки с id больше курсора,
 * лимит на единицу больше запрошенного, чтобы знать, есть ли следующая.
 * Ведущий столбец отбора — первичный ключ, а не company_id: компании
 * у чтения нет по построению («Индекс следует за запросом»).
 */
final readonly class ActiveOzonConnectionsForServiceQuery
{
    public const int DEFAULT_LIMIT = 50;

    public const int MAX_LIMIT = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param string|null $afterId последний id предыдущей страницы
     */
    public function build(?string $afterId, int $limit): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'company_id', 'credentials_ciphertext', 'credentials_key_version', 'advertising_state', 'updated_at')
            ->from('marketplace_account')
            ->where('marketplace = :marketplace')
            ->andWhere('state = :state')
            ->setParameter('marketplace', 'ozon')
            ->setParameter('state', 'active')
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit + 1);

        if (null !== $afterId) {
            $query->andWhere('id > :afterId')->setParameter('afterId', $afterId);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): ActiveOzonConnectionRow
    {
        $keyVersion = $row['credentials_key_version'];

        return new ActiveOzonConnectionRow(
            marketplaceAccountId: self::stringValue($row['id']),
            companyId: self::stringValue($row['company_id']),
            credentialsCiphertext: self::stringValue($row['credentials_ciphertext']),
            credentialsKeyVersion: \is_int($keyVersion) ? $keyVersion : (int) self::stringValue($keyVersion),
            advertisingActive: 'active' === $row['advertising_state'],
            updatedAt: new \DateTimeImmutable(self::stringValue($row['updated_at']), new \DateTimeZone('UTC')),
        );
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in an active Ozon connection row.');
        }

        return $value;
    }
}
