<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Query;

/**
 * Строка ActiveOzonConnectionsForServiceQuery. Ключи — ещё шифротекстом:
 * расшифровывает только контроллер выдачи, непосредственно перед ответом.
 */
final readonly class ActiveOzonConnectionRow
{
    public function __construct(
        public string $marketplaceAccountId,
        public string $companyId,
        public string $credentialsCiphertext,
        public int $credentialsKeyVersion,
        public bool $advertisingActive,
        public \DateTimeImmutable $updatedAt,
    ) {
    }
}
