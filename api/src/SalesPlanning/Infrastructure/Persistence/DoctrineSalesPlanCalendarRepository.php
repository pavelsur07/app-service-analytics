<?php

declare(strict_types=1);

namespace App\SalesPlanning\Infrastructure\Persistence;

use App\SalesPlanning\Domain\SalesPlanCalendarEntry;
use App\SalesPlanning\Domain\SalesPlanCalendarRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/** Stages human changes in the same EntityManager as the plan and audit. */
final readonly class DoctrineSalesPlanCalendarRepository implements SalesPlanCalendarRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function entriesForUpdate(string $companyId, Uuid $planId, ?string $marketplaceSku = null): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('e')->from(SalesPlanCalendarEntry::class, 'e')
            ->where('e.companyId = :companyId AND e.salesPlanId = :planId')
            ->setParameter('companyId', Uuid::fromString($companyId), 'uuid')
            ->setParameter('planId', $planId, 'uuid')
            ->setMaxResults(SalesPlanCalendarEntry::MaxEntries + 1);
        if (null !== $marketplaceSku) {
            $query->andWhere('e.marketplaceSku = :sku')->setParameter('sku', $marketplaceSku);
        }
        /** @var list<SalesPlanCalendarEntry> $entries */
        $entries = $query->getQuery()->getResult();
        if (\count($entries) > SalesPlanCalendarEntry::MaxEntries) {
            throw new \DomainException('В календаре больше 500 сохранённых записей.');
        }

        return $entries;
    }

    public function allSkusExist(string $companyId, Uuid $planId, array $marketplaceSkus): bool
    {
        if (\count($marketplaceSkus) > SalesPlanCalendarEntry::MaxEntries) {
            throw new \InvalidArgumentException('В календаре допускается не больше 500 SKU.');
        }
        if ([] === $marketplaceSkus) {
            return true;
        }

        $result = $this->entityManager->getConnection()->fetchOne(
            'SELECT NOT EXISTS (
                SELECT 1 FROM jsonb_array_elements_text(CAST(:skus AS jsonb)) s(sku)
                WHERE NOT EXISTS (
                    SELECT 1 FROM sales_plan_line l
                    WHERE l.company_id = :companyId AND l.sales_plan_id = :planId
                      AND l.marketplace_sku = s.sku
                )
             )',
            ['companyId' => $companyId, 'planId' => $planId->toRfc4122(), 'skus' => json_encode($marketplaceSkus, \JSON_THROW_ON_ERROR)],
        );
        if (!\is_bool($result)) {
            throw new \UnexpectedValueException('Invalid calendar SKU membership result.');
        }

        return $result;
    }

    public function add(SalesPlanCalendarEntry $entry): void
    {
        $this->entityManager->persist($entry);
    }

    public function remove(SalesPlanCalendarEntry $entry): void
    {
        $this->entityManager->remove($entry);
    }
}
