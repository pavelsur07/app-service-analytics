<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\AuditRecordRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAuditRecordRepository implements AuditRecordRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(AuditRecord $record): void
    {
        $this->entityManager->persist($record);
        $this->entityManager->flush();
    }
}
