<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\DiscardAccountOutcome;
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

    public function deleteIfNoHistory(string $companyId, Uuid $id, \Closure $isEligibleForDeletion, Uuid $actorUserId): DiscardAccountOutcome
    {
        $outcome = DiscardAccountOutcome::NotFound;

        $this->entityManager->wrapInTransaction(function () use ($companyId, $id, $isEligibleForDeletion, $actorUserId, &$outcome): void {
            $connection = $this->entityManager->getConnection();

            // companyId в самом запросе — изоляция арендаторов на уровне
            // SQL (CLAUDE.md §1), а не доверием к тому, что вызывающий
            // уже проверил.
            $row = $connection->fetchAssociative(
                'SELECT name, external_shop_id FROM marketplace_account WHERE id = :id AND company_id = :companyId',
                ['id' => $id->toRfc4122(), 'companyId' => $companyId],
            );
            if (false === $row) {
                // outcome остаётся NotFound: подключения с таким id
                // у этой компании нет — ни разу не существовало, ни уже
                // удалено (повторный вызов идемпотентен).
                return;
            }

            // Последнее, что происходит перед DELETE, внутри этой же ещё
            // не закоммиченной транзакции — приём против гонки описан
            // в докблоке интерфейса (MarketplaceAccountRepository::deleteIfNoHistory).
            if (!$isEligibleForDeletion()) {
                $outcome = DiscardAccountOutcome::InUse;

                return;
            }

            $affected = $connection->executeStatement(
                'DELETE FROM marketplace_account WHERE id = :id AND company_id = :companyId',
                ['id' => $id->toRfc4122(), 'companyId' => $companyId],
            );
            if (0 === $affected) {
                // Параллельный вызов удалил ту же строку между SELECT выше
                // и этим DELETE — тот же класс защиты, что и у markBrokenIfActive:
                // условие живёт в самом DELETE, а не в проверке перед ним.
                return;
            }

            $this->entityManager->persist(AuditRecord::record(
                companyId: Uuid::fromString($companyId),
                actorUserId: $actorUserId,
                action: AuditAction::MarketplaceAccountDiscarded,
                subjectId: $id,
                previousValue: \sprintf('%s (%s)', self::stringValue($row['name']), self::stringValue($row['external_shop_id'])),
                newValue: null,
                occurredAt: new \DateTimeImmutable(),
            ));

            $outcome = DiscardAccountOutcome::Discarded;
        });

        return $outcome;
    }

    private static function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Expected a string value in a marketplace account row.');
        }

        return $value;
    }
}
