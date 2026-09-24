<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

use Symfony\Component\Uid\Uuid;

/**
 * Строка сверки хранилища сырья: поля, из которых пересобирается ключ
 * объекта. Тела здесь нет — его читает порт хранилища.
 */
final readonly class AllCompaniesRawObjectRow
{
    public function __construct(
        public Uuid $id,
        public string $companyId,
        public Uuid $marketplaceAccountId,
        public string $reportType,
        public \DateTimeImmutable $period,
        public string $bodyHash,
        public string $storageKey,
        public int $byteSize,
        public \DateTimeImmutable $receivedAt,
    ) {
    }
}
