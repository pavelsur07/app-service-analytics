<?php

declare(strict_types=1);

namespace App\Planning\Infrastructure\Repository;

use App\Planning\Domain\PlanImportPreview;
use App\Planning\Domain\PlanImportPreviewRepository;
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
                $this->entityManager->remove($existing);
                // Doctrine выполняет INSERT раньше DELETE. Освобождаем
                // частичный уникальный ключ истёкшего ready preview до
                // сохранения его замены в той же транзакции.
                $this->entityManager->flush();
            }

            /** @var list<PlanImportPreview> $ready */
            $ready = $this->entityManager->createQueryBuilder()->select('preview')->from(PlanImportPreview::class, 'preview')
                ->where('preview.companyId = :company')->andWhere('preview.marketplaceAccountId = :account')
                ->andWhere('preview.actorId = :actor')->andWhere('preview.status = :status')
                ->orderBy('preview.createdAt', 'DESC')->addOrderBy('preview.id', 'DESC')->setMaxResults(self::MAX_READY_PER_ACTOR)
                ->setParameter('company', $companyId, 'uuid')->setParameter('account', $preview->marketplaceAccountId(), 'uuid')
                ->setParameter('actor', $preview->actorId(), 'uuid')->setParameter('status', PlanImportPreview::STATUS_READY)
                ->getQuery()->getResult();
            if (\count($ready) >= self::MAX_READY_PER_ACTOR) {
                $this->entityManager->remove($ready[array_key_last($ready)]);
            }
            $this->entityManager->persist($preview);
            $this->entityManager->flush();

            return $preview;
        });

        return $stored;
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
