<?php

declare(strict_types=1);

namespace App\Identity\Application\Facade;

use App\Identity\Infrastructure\Query\ActiveOzonAccountRow;
use App\Identity\Infrastructure\Query\ActiveOzonAccountsQuery;

/**
 * Единственный вызывающий — DispatchActiveOzonSyncsAction (Ingestion,
 * планировщик синхронизации). Отдельный класс от IdentityFacade
 * намеренно: findActiveOzonSyncTargets — межарендаторное чтение,
 * сознательное исключение из CLAUDE.md §1 («Исключение — межарендаторное
 * чтение для операционных системных задач»), и держать его на отдельном
 * классе — единственный способ дать Deptrac применить границу
 * (deptrac.php: IdentityScheduleFacade доступен только
 * IngestionOperationalAction, не всему IngestionApplication, где
 * иначе транзитивно оказался бы виден любому будущему HTTP-контроллеру
 * Ingestion).
 */
final readonly class IdentityScheduleFacade
{
    public function __construct(
        private ActiveOzonAccountsQuery $activeOzonAccounts,
    ) {
    }

    /**
     * @return list<OzonAccountRef>
     */
    public function findActiveOzonSyncTargets(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->activeOzonAccounts->build()->executeQuery()->fetchAllAssociative();

        if (\count($rows) > ActiveOzonAccountsQuery::MAX_RESULTS) {
            // Громко, не тихая отдача первых 200 — часть компаний тогда
            // молча перестала бы синхронизироваться (CLAUDE.md §5:
            // список без лимита не отдаётся никогда, но лимит здесь —
            // тревога о реальном масштабе, не тихая обрезка).
            throw new \RuntimeException(\sprintf('Активных Ozon-подключений больше защитного потолка %d — нужна курсорная выборка, не разовый список.', ActiveOzonAccountsQuery::MAX_RESULTS));
        }

        return array_map(
            static fn (array $row): OzonAccountRef => self::toAccountRef(ActiveOzonAccountsQuery::mapRow($row)),
            $rows,
        );
    }

    private static function toAccountRef(ActiveOzonAccountRow $row): OzonAccountRef
    {
        return new OzonAccountRef(
            companyId: $row->companyId,
            marketplaceAccountId: $row->marketplaceAccountId,
        );
    }
}
