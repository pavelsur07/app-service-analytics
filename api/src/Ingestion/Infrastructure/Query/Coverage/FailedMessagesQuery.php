<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query\Coverage;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Упавшие сообщения кабинета из очереди `failed` — для статуса «ошибка»
 * в отчёте о полноте данных.
 *
 * Очередь — таблица Messenger, колонок компании в ней нет: отбор по тексту
 * тела лишь сужает выборку, а принадлежность решает вызывающий по
 * разобранному сообщению — компания и кабинет сверяются точно
 * (CLAUDE.md §1). Тело разбирает штатный сериализатор очереди; то, что
 * не разбирается (класс сообщения удалён), пропускается.
 */
final readonly class FailedMessagesQuery
{
    public const int MAX_MESSAGES = 1_000;

    private const string TIMEZONE = 'Europe/Moscow';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return list<FailedMessage>
     */
    public function forAccount(string $marketplaceAccountId): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('body', 'created_at')
            ->from('messenger_messages')
            ->where('queue_name = :failed')
            ->andWhere('body LIKE :account')
            ->setParameter('failed', 'failed')
            ->setParameter('account', '%'.$marketplaceAccountId.'%')
            ->orderBy('id', 'DESC')
            ->setMaxResults(self::MAX_MESSAGES)
            ->executeQuery()
            ->fetchAllAssociative();

        $serializer = new PhpSerializer();
        $messages = [];
        foreach ($rows as $row) {
            if (!\is_string($row['body']) || !\is_string($row['created_at'])) {
                continue;
            }
            try {
                // PhpSerializer читает только тело: заголовки ему не нужны.
                $message = $serializer->decode(['body' => $row['body']])->getMessage();
            } catch (\Throwable) {
                continue;
            }

            $failedOn = (new \DateTimeImmutable($row['created_at'], new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone(self::TIMEZONE))
                ->setTime(0, 0);
            $messages[] = new FailedMessage($message, $failedOn);
        }

        return $messages;
    }
}
