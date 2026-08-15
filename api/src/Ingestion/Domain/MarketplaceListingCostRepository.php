<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * Интерфейс в Domain, реализация — Infrastructure/Persistence.
 *
 * Каждый метод первым параметром принимает companyId (CLAUDE.md §1):
 * поиска позиции по одному лишь идентификатору здесь нет и не будет —
 * себестоимость чужой компании это её коммерческая тайна, и защита
 * не должна зависеть от того, что вызывающий передал согласованную пару.
 *
 * Конфликты наружу отдаются исключениями Doctrine, а сценарий переводит
 * их в исход (тот же приём, что у ReplaceMarketplaceCredentialsAction):
 * своя иерархия исключений ради двух случаев была бы слоем без выгоды.
 */
interface MarketplaceListingCostRepository
{
    /**
     * Сохраняет новую позицию и фиксирует единицу работы целиком —
     * вместе с записью аудит-журнала, поставленной сценарием.
     *
     * @throws \Doctrine\DBAL\Exception\UniqueConstraintViolationException цена с этой датой уже задана
     */
    public function add(MarketplaceListingCost $cost): void;

    public function get(string $companyId, Uuid $id): ?MarketplaceListingCost;

    /**
     * Фиксирует изменения уже загруженной позиции.
     *
     * @throws \Doctrine\ORM\OptimisticLockException позицию изменил кто-то ещё
     */
    public function save(): void;
}
