<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/**
 * О какой карточке и на какой момент спрашивают (ADR-016).
 *
 * Кабинет здесь обязателен, а не выведен из артикула: после
 * переподключения магазина в истории цен остаются строки обоих
 * кабинетов, и выбор без кабинета взял бы цену чужого. Соинвест
 * получился бы правдоподобным и неверным — худший исход из возможных,
 * потому что от настоящего его не отличить.
 */
final readonly class ListingSnapshotRequest
{
    public function __construct(
        public string $marketplaceSku,
        public string $marketplaceAccountId,
        public \DateTimeImmutable $at,
    ) {
    }
}
