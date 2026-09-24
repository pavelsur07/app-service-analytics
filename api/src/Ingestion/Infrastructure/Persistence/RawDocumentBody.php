<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\RawDocumentStorage;
use App\Ingestion\Domain\RawObjectKey;
use Symfony\Component\Uid\Uuid;

/**
 * Тело сырого документа по строке marketplace_raw_document (ADR-024):
 * из колонки body, если оно там есть (документы до этапа 2 и записанные
 * или дописанные при аварийном RAW_BODY_STORE=database), иначе из S3
 * по ключу. База — первой: при недоступном хранилище аварийный режим
 * обязан давать читать всё, у чего тело в базе есть.
 *
 * Одно место на оба чтения (репозиторий и история отгрузок): правило
 * «откуда тело» и сверка ключа не должны разойтись.
 */
final readonly class RawDocumentBody
{
    /**
     * Все нынешние отчёты площадок — JSON. Другой формат потребует
     * расширения по типу отчёта — и новой ветки здесь, а не угадывания.
     */
    public const string EXTENSION = 'json';

    public const string COLUMNS = 'marketplace_account_id, report_type, period, body_hash, body, storage_key';

    public function __construct(
        private RawDocumentStorage $storage,
    ) {
    }

    public static function key(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $reportType,
        \DateTimeImmutable $period,
        string $bodyHash,
    ): RawObjectKey {
        return RawObjectKey::for($companyId, $marketplaceAccountId, $reportType, $period, $bodyHash, self::EXTENSION);
    }

    /**
     * $companyId — компания, которой company-scoped запрос нашёл строку:
     * ключ пересобирается из неё и полей строки, а не берётся из
     * storage_key на веру (CLAUDE.md §1).
     *
     * @param array<string, mixed> $row колонки self::COLUMNS
     */
    public function read(string $companyId, array $row): string
    {
        $body = $row['body'] ?? null;
        if (\is_string($body)) {
            return $body;
        }
        $storageKey = $row['storage_key'] ?? null;
        if (null === $storageKey) {
            throw new \UnexpectedValueException('У сырого документа нет тела ни в базе, ни в хранилище.');
        }

        $account = $row['marketplace_account_id'] ?? null;
        $reportType = $row['report_type'] ?? null;
        $period = $row['period'] ?? null;
        $bodyHash = $row['body_hash'] ?? null;
        if (!\is_string($storageKey) || !\is_string($account) || !\is_string($reportType) || !\is_string($period) || !\is_string($bodyHash)) {
            throw new \UnexpectedValueException('Malformed raw document row.');
        }

        $key = self::key(Uuid::fromString($companyId), Uuid::fromString($account), $reportType, new \DateTimeImmutable($period), $bodyHash);
        if ($key->toString() !== $storageKey) {
            throw new \UnexpectedValueException(\sprintf('Ключ объекта сырья в строке не совпадает с пересобранным: %s', $storageKey));
        }

        return $this->storage->get($companyId, $key);
    }
}
