<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

    public function markBrokenIfActive(string $companyId, Uuid $id): bool
    {
        // DBAL, не ORM: условие `state = 'active'` обязано быть внутри
        // UPDATE, иначе проверка и запись расходятся между транзакциями
        // (тот же приём, что в DoctrineExtensionTokenRepository::revokeIfActive).
        // companyId в условии — изоляция арендаторов на уровне SQL.
        $affected = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE marketplace_account
                SET state = :broken
                WHERE id = :id AND company_id = :companyId AND state = :active
                SQL,
            [
                'broken' => MarketplaceAccountState::Broken->value,
                'active' => MarketplaceAccountState::Active->value,
                'id' => $id->toRfc4122(),
                'companyId' => $companyId,
            ],
        );

        return $affected > 0;
    }

    public function tryConnect(MarketplaceAccount $account, AuditRecord $trail): bool
    {
        try {
            $this->entityManager->wrapInTransaction(function () use ($account, $trail): void {
                $this->entityManager->persist($account);
                $this->entityManager->persist($trail);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // PostgreSQL называет нарушенное ограничение в сообщении.
            // Поглощаем только уникальность кабинета — глобальную и внутри
            // компании: любое другое нарушение это наш дефект, и молча
            // превращать его в «кабинет занят» значит спрятать его навсегда.
            $message = $exception->getMessage();
            $isCabinetTaken =
                str_contains($message, 'uq_marketplace_account_marketplace_external_shop_active')
                || str_contains($message, 'uq_marketplace_account_company_marketplace_external_shop');

            if (!$isCabinetTaken) {
                throw $exception;
            }

            return false;
        }

        return true;
    }
}
