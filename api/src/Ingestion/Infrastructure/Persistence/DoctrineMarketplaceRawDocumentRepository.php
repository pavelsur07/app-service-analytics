<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceRawDocument;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\RawDocumentStorage;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * DBAL, не ORM (CLAUDE.md §6: raw — данные пайплайна, не человека).
 * INSERT ... ON CONFLICT DO UPDATE (no-op на существующее значение)
 * по естественному ключу — не DO NOTHING: тот не даёт RETURNING id
 * при конфликте, а вызывающему нужен id уже существующей строки,
 * не только успешной вставки.
 *
 * Тело (ADR-024): при RAW_BODY_STORE=s3 — объект в хранилище, затем
 * строка с body = NULL и ключом; сбой хранилища — исключение до вставки,
 * повтор очереди пишет тот же ключ. RAW_BODY_STORE=database — аварийный
 * возврат записи в базу на время наблюдения этапа 2, убирается на этапе 3.
 * На конфликте возвращается id существующей строки. Содержимое у неё то же
 * (тот же body_hash), но место хранения может быть другим: строка до этапа 2
 * — с телом в базе, строка этапа 2 — с телом в S3. Поэтому конфликт
 * дописывает недостающее место, не трогая имеющееся (COALESCE):
 * в режиме s3 старая строка получает ключ только что записанного объекта,
 * в аварийном database строка этапа 2 получает тело в базе — и повторное
 * чтение (FetchOzonReturnsHandler) не идёт в недоступное хранилище.
 * Содержимое сырья не меняется (ADR-006: raw не мутируется) — меняется
 * только то, где его можно прочитать.
 */
final readonly class DoctrineMarketplaceRawDocumentRepository implements MarketplaceRawDocumentRepository
{
    public const string STORE_S3 = 's3';

    public const string STORE_DATABASE = 'database';

    public function __construct(
        private Connection $connection,
        private RawDocumentStorage $storage,
        private RawDocumentBody $rawDocumentBody,
        private string $rawBodyStore,
    ) {
    }

    public function add(MarketplaceRawDocument $document): Uuid
    {
        $companyId = $document->companyId()->toRfc4122();
        [$body, $storageKey, $byteSize] = match ($this->rawBodyStore) {
            self::STORE_S3 => $this->putObject($companyId, $document),
            self::STORE_DATABASE => [$document->body(), null, null],
            default => throw new \LogicException(\sprintf('RAW_BODY_STORE — «%s» или «%s», не «%s».', self::STORE_S3, self::STORE_DATABASE, $this->rawBodyStore)),
        };

        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO marketplace_raw_document
                    (id, company_id, marketplace_account_id, report_type, period, body_hash, body, storage_key, byte_size, received_at)
                VALUES
                    (:id, :companyId, :marketplaceAccountId, :reportType, :period, :bodyHash, :body, :storageKey, :byteSize, :receivedAt)
                ON CONFLICT (company_id, marketplace_account_id, report_type, period, body_hash)
                DO UPDATE SET
                    body = COALESCE(marketplace_raw_document.body, EXCLUDED.body),
                    storage_key = COALESCE(marketplace_raw_document.storage_key, EXCLUDED.storage_key),
                    byte_size = COALESCE(marketplace_raw_document.byte_size, EXCLUDED.byte_size)
                RETURNING id
                SQL,
            [
                'id' => $document->id()->toRfc4122(),
                'companyId' => $companyId,
                'marketplaceAccountId' => $document->marketplaceAccountId()->toRfc4122(),
                'reportType' => $document->reportType(),
                'period' => $document->period()->format('Y-m-d'),
                'bodyHash' => $document->bodyHash(),
                'body' => $body,
                'storageKey' => $storageKey,
                'byteSize' => $byteSize,
                'receivedAt' => $document->receivedAt()->format('Y-m-d H:i:sP'),
            ],
        );

        \assert(\is_string($id));

        return Uuid::fromString($id);
    }

    public function body(string $companyId, Uuid $marketplaceAccountId, Uuid $id): string
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.RawDocumentBody::COLUMNS.' FROM marketplace_raw_document WHERE company_id = :companyId AND marketplace_account_id = :marketplaceAccountId AND id = :id',
            [
                'companyId' => $companyId,
                'marketplaceAccountId' => $marketplaceAccountId->toRfc4122(),
                'id' => $id->toRfc4122(),
            ],
        );
        if (false === $row) {
            throw new \RuntimeException("Raw marketplace document {$id->toRfc4122()} not found.");
        }

        return $this->rawDocumentBody->read($companyId, $row);
    }

    /**
     * @return array{null, string, int}
     */
    private function putObject(string $companyId, MarketplaceRawDocument $document): array
    {
        $key = RawDocumentBody::key(
            $document->companyId(),
            $document->marketplaceAccountId(),
            $document->reportType(),
            $document->period(),
            $document->bodyHash(),
        );
        $this->storage->put($companyId, $key, $document->body());

        return [null, $key->toString(), \strlen($document->body())];
    }

    public function existsForAccount(string $companyId, Uuid $marketplaceAccountId): bool
    {
        $exists = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM marketplace_raw_document
                    WHERE company_id = :companyId AND marketplace_account_id = :marketplaceAccountId
                )
                SQL,
            [
                'companyId' => $companyId,
                'marketplaceAccountId' => $marketplaceAccountId->toRfc4122(),
            ],
        );

        return (bool) $exists;
    }
}
