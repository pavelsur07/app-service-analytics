<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Coverage;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Упавшие сообщения кабинета из очереди `failed` — для статуса «ошибка»
 * в отчёте о полноте данных.
 *
 * Очередь — таблица Messenger, колонок компании в ней нет. Компания
 * и кабинет всё равно стоят в условии запроса (CLAUDE.md §1) — отбором
 * по тексту тела, — а точную принадлежность подтверждает разобранное
 * сообщение (`FailedLoads`). Читается пачками по `id` от новых к старым:
 * ни одно сообщение не отбрасывается молча.
 *
 * Тело, которое не является корректным UTF-8 (например, текст ошибки
 * площадки обрезан посреди символа), PhpSerializer кладёт в base64 —
 * идентификаторов в нём текстом не видно. Такие тела отбираются все:
 * в base64 нет кавычки, а в сериализованном PHP она есть всегда.
 * Их принадлежность подтверждает тот же разбор.
 */
final readonly class FailedMessagesQuery
{
    public const int BATCH = 500;

    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Пачка до `BATCH` сообщений с `id` меньше `$beforeId` (`null` — с самого
     * нового).
     */
    public function build(string $companyId, string $marketplaceAccountId, ?int $beforeId): QueryBuilder
    {
        $query = $this->connection->createQueryBuilder()
            ->select('id', 'body', 'created_at')
            ->from('messenger_messages')
            ->where('queue_name = :failed')
            ->andWhere("(body LIKE :company AND body LIKE :account) OR body NOT LIKE '%\"%'")
            ->setParameter('failed', 'failed')
            ->setParameter('company', '%'.$companyId.'%')
            ->setParameter('account', '%'.$marketplaceAccountId.'%')
            ->orderBy('id', 'DESC')
            ->setMaxResults(self::BATCH);

        if (null !== $beforeId) {
            $query->andWhere('id < :before')->setParameter('before', $beforeId);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function id(array $row): int
    {
        $id = $row['id'] ?? null;
        if (!\is_int($id) && !(\is_string($id) && ctype_digit($id))) {
            throw new \UnexpectedValueException('Failed message row has no id.');
        }

        return (int) $id;
    }

    /**
     * Разобранное сообщение строки; `null`, если тело не разбирается
     * (класс сообщения удалён) — в отчёт такое не попадает.
     *
     * @param array<string, mixed> $row
     */
    public static function decode(array $row): ?FailedMessage
    {
        if (!\is_string($row['body'] ?? null) || !\is_string($row['created_at'] ?? null)) {
            return null;
        }

        try {
            // PhpSerializer читает только тело: заголовки ему не нужны.
            $message = (new PhpSerializer())->decode(['body' => $row['body']])->getMessage();
        } catch (\Throwable) {
            return null;
        }

        $failedAt = new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC'));
        $failedOn = $failedAt->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);

        return new FailedMessage($message, $failedOn, $failedAt);
    }
}
