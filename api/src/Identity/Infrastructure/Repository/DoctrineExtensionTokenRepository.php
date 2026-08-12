<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Repository;

use App\Identity\Domain\ExtensionToken;
use App\Identity\Domain\ExtensionTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineExtensionTokenRepository implements ExtensionTokenRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function add(ExtensionToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function save(ExtensionToken $token): void
    {
        $this->entityManager->persist($token);
        $this->entityManager->flush();
    }

    public function revokeIfActive(string $companyId, Uuid $id, Uuid $revokedByUserId, \DateTimeImmutable $at): bool
    {
        // DBAL, не ORM: условие `revoked_at IS NULL` обязано быть внутри
        // UPDATE, иначе проверка и запись расходятся между транзакциями
        // (тот же приём прямого SQL, что в DoctrineCompanyMemberRepository).
        // companyId в условии — изоляция арендаторов на уровне SQL.
        $affected = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                UPDATE extension_token
                SET revoked_at = :at, revoked_by_user_id = :revokedBy
                WHERE id = :id AND company_id = :companyId AND revoked_at IS NULL
                SQL,
            [
                'at' => $at->format('Y-m-d H:i:s'),
                'revokedBy' => $revokedByUserId->toRfc4122(),
                'id' => $id->toRfc4122(),
                'companyId' => $companyId,
            ],
        );

        return $affected > 0;
    }

    public function get(string $companyId, Uuid $id): ?ExtensionToken
    {
        // companyId в самом запросе, не фильтром после fetch — изоляция
        // арендаторов проверяется на уровне SQL, не доверием к вызывающему
        // (тот же приём, что в DoctrineMarketplaceAccountRepository::get).
        $token = $this->entityManager->createQueryBuilder()
            ->select('token')
            ->from(ExtensionToken::class, 'token')
            ->where('token.id = :id')
            ->andWhere('token.companyId = :companyId')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('companyId', $companyId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();

        \assert(null === $token || $token instanceof ExtensionToken);

        return $token;
    }
}
