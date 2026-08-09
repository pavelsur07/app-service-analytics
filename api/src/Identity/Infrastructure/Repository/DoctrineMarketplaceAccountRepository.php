<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\Marketplace;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineMarketplaceAccountRepository implements MarketplaceAccountRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(MarketplaceAccount $account): void
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }

    public function get(string $companyId, Uuid $id): ?MarketplaceAccount
    {
        // companyId в самом запросе, не фильтром после fetch — изоляция
        // арендаторов проверяется на уровне SQL, не доверием к вызывающему.
        $account = $this->entityManager->createQueryBuilder()
            ->select('account')
            ->from(MarketplaceAccount::class, 'account')
            ->where('account.id = :id')
            ->andWhere('account.companyId = :companyId')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('companyId', $companyId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        \assert(null === $account || $account instanceof MarketplaceAccount);

        return $account;
    }

    public function findAllActive(Marketplace $marketplace): array
    {
        /** @var list<MarketplaceAccount> $accounts */
        $accounts = $this->entityManager->createQueryBuilder()
            ->select('account')
            ->from(MarketplaceAccount::class, 'account')
            ->where('account.marketplace = :marketplace')
            ->andWhere('account.state = :state')
            ->setParameter('marketplace', $marketplace)
            ->setParameter('state', MarketplaceAccountState::Active)
            ->getQuery()
            ->getResult();

        return $accounts;
    }
}
