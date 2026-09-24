<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Storage;

use App\Ingestion\Domain\RawDocumentStorage;
use App\Ingestion\Domain\RawObjectKey;
use App\Ingestion\Domain\RawObjectNotFound;
use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\S3Client;

/**
 * Тело — gzip исходных байтов (ADR-024): JSON ответов площадки сжимается
 * примерно в шесть раз. Распаковка — здесь же, вызывающий получает
 * ровно то, что прислала площадка.
 */
final readonly class S3RawDocumentStorage implements RawDocumentStorage
{
    public function __construct(
        private S3Client $s3,
        private string $rawStorageBucket,
    ) {
    }

    public function put(string $companyId, RawObjectKey $key, string $body): void
    {
        self::assertBelongs($companyId, $key);

        $compressed = gzencode($body, 6);
        if (false === $compressed) {
            throw new \RuntimeException(\sprintf('Не удалось сжать тело для %s', $key->toString()));
        }

        // Условная запись: If-None-Match: * — объект пишется, только если
        // его ещё нет. В версионируемом бакете (ADR-024) безусловный PUT
        // того же ключа добавлял бы версию на каждую повторную загрузку
        // скользящего окна. Проверка «есть ли» перед записью не годится:
        // это «найти, и если нет — записать» (CLAUDE.md §4); условие
        // проверяет сам провайдер, атомарно.
        //
        // resolve() — дождаться ответа: без него async-aws отправит запрос
        // лениво, и ошибка записи всплыла бы уже после того, как вызывающий
        // счёл сырьё сохранённым.
        try {
            $this->s3->putObject([
                'Bucket' => $this->rawStorageBucket,
                'Key' => $key->toString(),
                'Body' => $compressed,
                'ContentType' => 'application/gzip',
                'IfNoneMatch' => '*',
            ])->resolve();
        } catch (ClientException $conflict) {
            // 412 — объект уже есть. Ключ — хэш содержимого, значит
            // и содержимое то же: запись уже выполнена.
            if (412 !== $conflict->getCode()) {
                throw $conflict;
            }
        }
    }

    public function get(string $companyId, RawObjectKey $key): string
    {
        self::assertBelongs($companyId, $key);

        try {
            $compressed = $this->s3->getObject([
                'Bucket' => $this->rawStorageBucket,
                'Key' => $key->toString(),
            ])->getBody()->getContentAsString();
        } catch (NoSuchKeyException $missing) {
            throw RawObjectNotFound::forKey($key, $missing);
        } catch (ClientException $failure) {
            // Провайдер может ответить 404 без кода NoSuchKey в теле —
            // это то же отсутствие объекта; остальные 4xx — отказ.
            if (404 === $failure->getCode()) {
                throw RawObjectNotFound::forKey($key, $failure);
            }

            throw $failure;
        }

        $body = gzdecode($compressed);
        if (false === $body) {
            throw new \RuntimeException(\sprintf('Объект сырья повреждён — не gzip: %s', $key->toString()));
        }

        return $body;
    }

    /**
     * «Нет» — только 404. Отказ в доступе и сбой провайдера — исключение,
     * а не false: иначе сломанные ключи выглядели бы как пропавший объект.
     * Поэтому HeadObject с перехватом ровно NoSuchKey, а не waiter
     * objectExists(), у которого любой не-200 — просто «не успех».
     */
    public function exists(string $companyId, RawObjectKey $key): bool
    {
        self::assertBelongs($companyId, $key);

        try {
            $this->s3->headObject([
                'Bucket' => $this->rawStorageBucket,
                'Key' => $key->toString(),
            ])->resolve();
        } catch (NoSuchKeyException) {
            return false;
        } catch (ClientException $failure) {
            // У ответа на HEAD нет тела, и 404 может прийти без кода NoSuchKey.
            if (404 === $failure->getCode()) {
                return false;
            }

            throw $failure;
        }

        return true;
    }

    private static function assertBelongs(string $companyId, RawObjectKey $key): void
    {
        if (!$key->belongsTo($companyId)) {
            // Ошибка программы, а не данных: ключ собран для другой
            // компании. Молча выполнить запрос значило бы нарушить §1.
            throw new \LogicException(\sprintf('Ключ объекта сырья не принадлежит компании %s.', $companyId));
        }
    }
}
