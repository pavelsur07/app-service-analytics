<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Persistence;

use App\Ingestion\Domain\MarketplaceListingCost;
use App\Ingestion\Domain\MarketplaceListingCostRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ORM, а не DBAL: себестоимость редактирует человек (CLAUDE.md §6),
 * ей нужны версия для оптимистической блокировки (ADR-008) и запись
 * аудит-журнала той же транзакцией (ADR-011). DBAL-апсерт, которым
 * пишутся факты и каталог, ни того, ни другого не даёт.
 */
final readonly class DoctrineMarketplaceListingCostRepository implements MarketplaceListingCostRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(MarketplaceListingCost $cost): void
    {
        $this->entityManager->persist($cost);
        $this->entityManager->flush();
    }

    public function get(string $companyId, Uuid $id): ?MarketplaceListingCost
    {
        // Условие по компании в самом запросе, а не проверкой после
        // загрузки: найти чужую строку и отбросить её в PHP — это уже
        // прочитать чужие данные (CLAUDE.md §1).
        $cost = $this->entityManager
            ->createQuery(
                'SELECT c FROM '.MarketplaceListingCost::class.' c WHERE c.companyId = :companyId AND c.id = :id',
            )
            ->setParameter('companyId', Uuid::fromString($companyId), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getOneOrNullResult();

        \assert(null === $cost || $cost instanceof MarketplaceListingCost);

        return $cost;
    }

    public function save(): void
    {
        $this->entityManager->flush();
    }
}
