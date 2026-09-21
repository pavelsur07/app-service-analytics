<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Repository;

use App\Planning\Domain\PlanImportPreview;
use App\Planning\Domain\PlanImportPreviewRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePlanImportPreviewRepository implements PlanImportPreviewRepository
{
    private const int MAX_READY_PER_ACTOR = 10;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function addOrGetReady(string $companyId, PlanImportPreview $preview, \DateTimeImmutable $now): PlanImportPreview
    {
        if ($companyId !== $preview->companyId()->toRfc4122()) {
            throw new \InvalidArgumentException('Компания preview не совпадает с областью репозитория.');
        }

        /** @var PlanImportPreview $stored */
        $stored = $this->entityManager->wrapInTransaction(function () use ($companyId, $preview, $now): PlanImportPreview {
            $this->entityManager->getConnection()->executeStatement(
                'SELECT pg_advisory_xact_lock(hashtextextended(:scope, 0))',
                ['scope' => implode('|', [
                    'planning-import-preview',
                    $companyId,
                    $preview->marketplaceAccountId()->toRfc4122(),
                    $preview->actorId()->toRfc4122(),
                ])],
            );
            $repository = $this->entityManager->getRepository(PlanImportPreview::class);
            $existing = $repository->findOneBy([
                'companyId' => $companyId,
                'marketplaceAccountId' => $preview->marketplaceAccountId(),
                'actorId' => $preview->actorId(),
                'fingerprint' => $preview->fingerprint(),
                'status' => PlanImportPreview::STATUS_READY,
            ]);
            if ($existing instanceof PlanImportPreview && !$existing->isExpired($now)) {
                return $existing;
            }
            if ($existing instanceof PlanImportPreview) {
                $this->deleteIfStillReady($companyId, $existing->id()->toRfc4122(), $now);
                $this->entityManager->detach($existing);
            }

            $readyIds = $this->entityManager->getConnection()->fetchFirstColumn(<<<'SQL'
                SELECT id::text
                FROM planning_import_preview
                WHERE company_id = :company AND marketplace_account_id = :account
                  AND actor_id = :actor AND status = :status
                ORDER BY created_at DESC, id DESC
                LIMIT 10
                SQL, [
                'company' => $companyId,
                'account' => $preview->marketplaceAccountId()->toRfc4122(),
                'actor' => $preview->actorId()->toRfc4122(),
                'status' => PlanImportPreview::STATUS_READY,
            ]);
            if (\count($readyIds) >= self::MAX_READY_PER_ACTOR) {
                $oldestId = $readyIds[array_key_last($readyIds)];
                \assert(\is_string($oldestId));
                $this->deleteIfStillReady($companyId, $oldestId);
            }
            $this->entityManager->persist($preview);
            $this->entityManager->flush();

            return $preview;
        });

        return $stored;
    }

    private function deleteIfStillReady(string $companyId, string $previewId, ?\DateTimeImmutable $expiredAt = null): void
    {
        $sql = 'DELETE FROM planning_import_preview WHERE company_id = :company AND id = :id AND status = :status';
        $parameters = ['company' => $companyId, 'id' => $previewId, 'status' => PlanImportPreview::STATUS_READY];
        $types = [];
        if (null !== $expiredAt) {
            $sql .= ' AND expires_at <= :expiredAt';
            $parameters['expiredAt'] = $expiredAt;
            $types['expiredAt'] = Types::DATETIME_IMMUTABLE;
        }
        $this->entityManager->getConnection()->executeStatement($sql, $parameters, $types);
    }

    public function get(string $companyId, string $marketplaceAccountId, string $previewId): ?PlanImportPreview
    {
        return $this->entityManager->getRepository(PlanImportPreview::class)->findOneBy([
            'id' => $previewId,
            'companyId' => $companyId,
            'marketplaceAccountId' => $marketplaceAccountId,
        ]);
    }
}
