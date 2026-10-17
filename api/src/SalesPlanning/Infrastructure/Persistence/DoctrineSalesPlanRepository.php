<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Persistence;

use App\SalesPlanning\Domain\SalesPlan;
use App\SalesPlanning\Domain\SalesPlanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * ORM, а не DBAL: план вводит человек (CLAUDE.md §6), ему нужны версия
 * (ADR-008) и запись аудит-журнала той же транзакцией (ADR-011).
 */
final readonly class DoctrineSalesPlanRepository implements SalesPlanRepository
{
    private const int FlushBatchSize = 1000;

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(SalesPlan $plan, array $lines): void
    {
        $this->entityManager->wrapInTransaction(function () use ($plan, $lines): void {
            $this->entityManager->persist($plan);
            $this->entityManager->flush();

            foreach (array_chunk($lines, self::FlushBatchSize) as $batch) {
                foreach ($batch as $line) {
                    $this->entityManager->persist($line);
                }

                $this->entityManager->flush();

                // Сброшенные строки больше не нужны единице работы:
                // без отсоединения память росла бы с числом строк,
                // и порционный flush ничего бы не экономил.
                foreach ($batch as $line) {
                    $this->entityManager->detach($line);
                }
            }
        });
    }

    public function get(string $companyId, Uuid $id): ?SalesPlan
    {
        // Условие по компании в самом запросе, а не проверкой после
        // загрузки: найти чужую строку и отбросить её в PHP — это уже
        // прочитать чужие данные (CLAUDE.md §1).
        $plan = $this->entityManager
            ->createQuery(
                'SELECT p FROM '.SalesPlan::class.' p WHERE p.companyId = :companyId AND p.id = :id',
            )
            ->setParameter('companyId', Uuid::fromString($companyId), 'uuid')
            ->setParameter('id', $id, 'uuid')
            ->getOneOrNullResult();

        \assert(null === $plan || $plan instanceof SalesPlan);

        return $plan;
    }
}
