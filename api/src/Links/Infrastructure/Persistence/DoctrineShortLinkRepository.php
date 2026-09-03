<?php

declare(strict_types=1);

namespace App\Links\Infrastructure\Persistence;

use App\Links\Domain\ShortLink;
use App\Links\Domain\ShortLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineShortLinkRepository implements ShortLinkRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function tryAdd(ShortLink $link): bool
    {
        $affected = $this->entityManager->getConnection()->executeStatement(
            <<<'SQL'
                INSERT INTO short_link
                    (id, code, name, target_url, status, version, created_by_admin_id, created_at, updated_at)
                VALUES
                    (:id, :code, :name, :targetUrl, :status, :version, :createdByAdminId, :createdAt, :updatedAt)
                ON CONFLICT (code) DO NOTHING
                SQL,
            [
                'id' => $link->id()->toRfc4122(),
                'code' => $link->code(),
                'name' => $link->name(),
                'targetUrl' => $link->targetUrl(),
                'status' => $link->status()->value,
                'version' => $link->version(),
                'createdByAdminId' => $link->createdByAdminId()->toRfc4122(),
                'createdAt' => $link->createdAt()->format('Y-m-d H:i:s'),
                'updatedAt' => $link->updatedAt()->format('Y-m-d H:i:s'),
            ],
        );

        return $affected > 0;
    }

    public function get(Uuid $id): ?ShortLink
    {
        $link = $this->entityManager
            ->createQuery('SELECT l FROM '.ShortLink::class.' l WHERE l.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getOneOrNullResult();

        \assert(null === $link || $link instanceof ShortLink);

        return $link;
    }

    public function save(): void
    {
        $this->entityManager->flush();
    }
}
