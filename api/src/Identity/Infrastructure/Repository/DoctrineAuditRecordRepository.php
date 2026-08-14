<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\AuditRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ORM, а не DBAL: запись рождается внутри пользовательского сценария
 * и обязана попасть в базу той же транзакцией, что и само изменение.
 * Отдельный DBAL-вызов это свойство как раз потерял бы.
 *
 * persist без flush намеренно — фиксацию делает сохранение сущности,
 * которую запись описывает (см. интерфейс).
 */
final readonly class DoctrineAuditRecordRepository implements AuditRecordRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function addToUnitOfWork(AuditRecord $record): void
    {
        $this->entityManager->persist($record);
    }
}
