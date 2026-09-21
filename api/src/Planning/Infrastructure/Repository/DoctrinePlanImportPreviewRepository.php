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
            $connection = $this->entityManager->getConnection();
            $scope = [
                'company' => $companyId,
                'account' => $preview->marketplaceAccountId()->toRfc4122(),
                'actor' => $preview->actorId()->toRfc4122(),
                'fingerprint' => $preview->fingerprint(),
            ];
            $connection->executeStatement(<<<'SQL'
                DELETE FROM planning_import_preview
                WHERE company_id = :company AND marketplace_account_id = :account
                  AND actor_id = :actor AND fingerprint = :fingerprint
                  AND status = 'ready' AND expires_at <= :now
                SQL, [...$scope, 'now' => $now], ['now' => Types::DATETIME_IMMUTABLE]);

            $insertedId = $connection->executeQuery(<<<'SQL'
                INSERT INTO planning_import_preview (
                  id, company_id, marketplace_account_id, actor_id, fingerprint,
                  normalized_rows, status, apply_result, created_at, expires_at, applied_at
                ) VALUES (
                  :id, :company, :account, :actor, :fingerprint,
                  :rows, 'ready', NULL, :createdAt, :expiresAt, NULL
                )
                ON CONFLICT (company_id, marketplace_account_id, actor_id, fingerprint)
                  WHERE ((status)::text = 'ready'::text)
                DO NOTHING
                RETURNING id::text
                SQL, [
                ...$scope,
                'id' => $preview->id()->toRfc4122(),
                'rows' => array_map(static fn ($row): array => $row->toArray(), $preview->rows()),
                'createdAt' => $preview->createdAt(),
                'expiresAt' => $preview->expiresAt(),
            ], [
                'rows' => Types::JSON,
                'createdAt' => Types::DATETIME_IMMUTABLE,
                'expiresAt' => Types::DATETIME_IMMUTABLE,
            ])->fetchOne();
            if (false === $insertedId) {
                $existing = $this->entityManager->getRepository(PlanImportPreview::class)->findOneBy([
                    'companyId' => $companyId,
                    'marketplaceAccountId' => $preview->marketplaceAccountId(),
                    'actorId' => $preview->actorId(),
                    'fingerprint' => $preview->fingerprint(),
                    'status' => PlanImportPreview::STATUS_READY,
                ]);
                if (!$existing instanceof PlanImportPreview) {
                    throw new \RuntimeException('Конфликт preview не удалось разрешить.');
                }

                return $existing;
            }

            $readyIds = $connection->fetchFirstColumn(<<<'SQL'
                SELECT id::text
                FROM planning_import_preview
                WHERE company_id = :company AND marketplace_account_id = :account
                  AND actor_id = :actor AND status = :status
                ORDER BY created_at DESC, id DESC
                LIMIT 11
                SQL, [
                'company' => $companyId,
                'account' => $preview->marketplaceAccountId()->toRfc4122(),
                'actor' => $preview->actorId()->toRfc4122(),
                'status' => PlanImportPreview::STATUS_READY,
            ]);
            if (\count($readyIds) > self::MAX_READY_PER_ACTOR) {
                $oldestId = $readyIds[array_key_last($readyIds)];
                \assert(\is_string($oldestId));
                $this->deleteIfStillReady($companyId, $oldestId);
            }

            return $preview;
        });

        return $stored;
    }

    private function deleteIfStillReady(string $companyId, string $previewId): void
    {
        $sql = 'DELETE FROM planning_import_preview WHERE company_id = :company AND id = :id AND status = :status';
        $parameters = ['company' => $companyId, 'id' => $previewId, 'status' => PlanImportPreview::STATUS_READY];
        $this->entityManager->getConnection()->executeStatement($sql, $parameters);
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
