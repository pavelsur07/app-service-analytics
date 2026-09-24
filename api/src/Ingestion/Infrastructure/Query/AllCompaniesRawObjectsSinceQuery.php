<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * Межарендаторное чтение для операционной системной задачи (CLAUDE.md §1):
 * сверка хранилища сырья на время наблюдения этапа 2 ADR-024 — у каждой
 * строки с ключом объекта есть объект, и его тело совпадает с body_hash.
 * Компании у задачи нет по построению: она проверяет хранилище целиком.
 *
 * Приём тот же, что у RecentlyIngestedAccountsQuery: отдельный DBAL-запрос
 * вне репозитория, а не метод, снимающий скоуп с company-scoped интерфейса.
 * Deptrac держит класс в узком слое IngestionRawVerificationQuery, доступном
 * только VerifyRawStorageCommand. Тела наружу не отдаются — только поля,
 * из которых пересобирается ключ; читает тело порт хранилища с company_id
 * строки.
 *
 * Индекс — idx_marketplace_raw_document_received_at: ведущий столбец —
 * диапазон по времени, компании у запроса нет («Индекс следует за запросом»).
 */
final readonly class AllCompaniesRawObjectsSinceQuery
{
    public const int PAGE = 200;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Keyset по (received_at, id): страница не зависит от объёма хранилища.
     */
    /**
     * $since = null — все строки с ключом: старые документы получают ключ
     * при повторной загрузке, сохраняя прежнее received_at, и в окно
     * по времени не попадают.
     */
    public function build(?\DateTimeImmutable $since, ?\DateTimeImmutable $cursorReceivedAt, ?Uuid $cursorId): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'company_id', 'marketplace_account_id', 'report_type', 'period', 'body_hash', 'storage_key', 'byte_size', 'received_at')
            ->from('marketplace_raw_document')
            ->where('storage_key IS NOT NULL')
            ->orderBy('received_at', 'ASC')
            ->addOrderBy('id', 'ASC')
            ->setMaxResults(self::PAGE);

        if (null !== $since) {
            $query->andWhere('received_at >= :since')
                ->setParameter('since', $since->format('Y-m-d H:i:s'));
        }
        if (null !== $cursorReceivedAt && null !== $cursorId) {
            $query->andWhere('(received_at, id) > (:cursorReceivedAt, :cursorId)')
                ->setParameter('cursorReceivedAt', $cursorReceivedAt->format('Y-m-d H:i:s'))
                ->setParameter('cursorId', $cursorId->toRfc4122());
        }

        return $query;
    }

    /**
     * Строки после since без ключа объекта: при RAW_BODY_STORE=s3 их быть
     * не должно — ненулевое число значит, что тело ушло в базу (аварийный
     * режим или код в обход хранилища).
     */
    public function countWithoutObjectSince(\DateTimeImmutable $since): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_raw_document WHERE storage_key IS NULL AND received_at >= :since',
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        if (!is_numeric($count)) {
            throw new \UnexpectedValueException('Malformed count of raw documents without object.');
        }

        return (int) $count;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function mapRow(array $row): AllCompaniesRawObjectRow
    {
        $strings = [];
        foreach (['id', 'company_id', 'marketplace_account_id', 'report_type', 'period', 'body_hash', 'storage_key', 'received_at'] as $column) {
            $value = $row[$column] ?? null;
            if (!\is_string($value)) {
                throw new \UnexpectedValueException(\sprintf('Malformed raw object row: %s.', $column));
            }
            $strings[$column] = $value;
        }
        $byteSize = $row['byte_size'] ?? null;
        if (!is_numeric($byteSize)) {
            throw new \UnexpectedValueException('Malformed raw object row: byte_size.');
        }

        return new AllCompaniesRawObjectRow(
            id: Uuid::fromString($strings['id']),
            companyId: $strings['company_id'],
            marketplaceAccountId: Uuid::fromString($strings['marketplace_account_id']),
            reportType: $strings['report_type'],
            period: new \DateTimeImmutable($strings['period']),
            bodyHash: $strings['body_hash'],
            storageKey: $strings['storage_key'],
            byteSize: (int) $byteSize,
            receivedAt: new \DateTimeImmutable($strings['received_at']),
        );
    }
}
