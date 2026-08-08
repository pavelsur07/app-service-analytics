<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

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
}
