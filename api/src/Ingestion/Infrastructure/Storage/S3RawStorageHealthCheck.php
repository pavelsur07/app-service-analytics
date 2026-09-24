<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Storage;

use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\S3\Exception\BucketAlreadyOwnedByYouException;
use AsyncAws\S3\S3Client;
use Symfony\Component\Uid\Uuid;

/**
 * Проверка связи с хранилищем сырья: запись, чтение и удаление пробного
 * объекта вне префикса сырья. Данных компаний не касается — отвечает
 * только на вопрос «ключи, бакет и сеть рабочие» (ADR-024,
 * docs/operations-checklist.md).
 */
final readonly class S3RawStorageHealthCheck
{
    private const string PREFIX = 'conwix/healthcheck';

    public function __construct(
        private S3Client $s3,
        private string $rawStorageBucket,
        private string $rawStorageEndpoint,
        private string $rawStorageRegion,
        private string $rawStorageAccessKey,
        private string $rawStorageSecretKey,
    ) {
    }

    public function bucket(): string
    {
        return $this->rawStorageBucket;
    }

    /**
     * На проде переменные хранилища до заведения бакета пусты
     * (docker-compose.prod.yml): отказ должен называть причину, а не
     * превращаться в запрос по пустому адресу.
     */
    private function assertConfigured(): void
    {
        // Все пять, а не только бакет: с пустым адресом клиент S3 берёт
        // адрес AWS по умолчанию и отправил бы туда подписанный запрос
        // с ключом Timeweb.
        $missing = array_keys(array_filter([
            'RAW_STORAGE_ENDPOINT' => $this->rawStorageEndpoint,
            'RAW_STORAGE_REGION' => $this->rawStorageRegion,
            'RAW_STORAGE_BUCKET' => $this->rawStorageBucket,
            'RAW_STORAGE_ACCESS_KEY' => $this->rawStorageAccessKey,
            'RAW_STORAGE_SECRET_KEY' => $this->rawStorageSecretKey,
        ], static fn (string $value): bool => '' === $value));

        if ([] !== $missing) {
            throw new \RuntimeException(\sprintf('Хранилище сырья не настроено, пусто: %s (ADR-024).', implode(', ', $missing)));
        }
    }

    /**
     * Только для песочницы: на проде бакет создаёт владелец в панели
     * провайдера, а у ключей приложения прав на создание нет.
     *
     * @return bool true — бакет создан сейчас, false — уже был
     */
    public function ensureBucket(): bool
    {
        $this->assertConfigured();

        // Создать и перехватить «уже ваш», а не спросить заранее: waiter
        // bucketExists() не отличает «нет бакета» от «нет доступа».
        // «Существует, но чужой» (BucketAlreadyExists) — ошибка и остаётся ею.
        try {
            $this->s3->createBucket(['Bucket' => $this->rawStorageBucket])->resolve();
        } catch (BucketAlreadyOwnedByYouException) {
            return false;
        }

        return true;
    }

    /**
     * Запись, условная перезапись, чтение, удаление.
     *
     * Вторая запись того же ключа — с If-None-Match: *, как пишет
     * S3RawDocumentStorage. Провайдер, соблюдающий условие, отвечает 412
     * и объект не трогает; провайдер, который условие игнорирует,
     * перезапишет объект — тогда в версионируемом бакете повторные
     * загрузки копят версии, и нужен lifecycle неактуальных версий (ADR-024).
     *
     * @return array{key: string, conditionalWrite: bool} ключ пробного объекта (уже удалён)
     *                                                    и соблюдает ли провайдер условную запись
     */
    public function probe(): array
    {
        $this->assertConfigured();

        $key = \sprintf('%s/%s.txt', self::PREFIX, Uuid::v7()->toRfc4122());
        $payload = bin2hex(random_bytes(16));

        $this->s3->putObject([
            'Bucket' => $this->rawStorageBucket,
            'Key' => $key,
            'Body' => $payload,
            'ContentType' => 'text/plain',
        ])->resolve();

        $conditionalWrite = false;
        try {
            try {
                $this->s3->putObject([
                    'Bucket' => $this->rawStorageBucket,
                    'Key' => $key,
                    'Body' => 'overwritten',
                    'ContentType' => 'text/plain',
                    'IfNoneMatch' => '*',
                ])->resolve();
            } catch (ClientException $conflict) {
                if (412 !== $conflict->getCode()) {
                    throw $conflict;
                }
                $conditionalWrite = true;
            }

            $read = $this->s3->getObject([
                'Bucket' => $this->rawStorageBucket,
                'Key' => $key,
            ])->getBody()->getContentAsString();

            // Соблюдено условие — лежит первое тело; нет — второе.
            $expected = $conditionalWrite ? $payload : 'overwritten';
            if ($read !== $expected) {
                throw new \RuntimeException(\sprintf('Прочитано не то, что записано: %s', $key));
            }
        } finally {
            $this->s3->deleteObject([
                'Bucket' => $this->rawStorageBucket,
                'Key' => $key,
            ])->resolve();
        }

        return ['key' => $key, 'conditionalWrite' => $conditionalWrite];
    }
}
