<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * История цены, выставленной продавцом в кабинете Ozon (ADR-015).
 *
 * Зачем отдельно от `marketplace_listing`: тот — снимок «что есть
 * сейчас», его писатель перезаписывает целиком на каждой синхронизации,
 * и вчерашняя цена исчезала бы вместе со строкой. А нужна именно
 * вчерашняя: СПП за 15 июля считается против цены, действовавшей
 * 15 июля, иначе получается число, выглядящее как настоящее.
 *
 * **Строка появляется только при изменении цены.** Синхронизация идёт
 * каждые полчаса; писать на каждый прогон значило бы держать три тысячи
 * одинаковых строк в сутки на компанию. Условие «отличается
 * от последнего известного» живёт внутри INSERT
 * (`DoctrineMarketplaceListingPriceWriter`), не в ветке кода: между
 * проверкой и вставкой два прогона прошли бы её оба (CLAUDE.md §4).
 *
 * `changed_at` — момент, когда мы **впервые увидели** это значение,
 * а не когда продавец его поменял: второго Ozon не сообщает. Цена
 * действует до следующей строки; дата окончания не хранится по той же
 * причине, что в ADR-013 — вторая дата рядом с первой неизбежно
 * рассинхронизируется.
 *
 * Ключ естественный, суррогата нет (CLAUDE.md §2). Не пишется ORM
 * (§6): наполняется синхронизацией, не человеком. Класс существует
 * ради migrations:diff, schema:validate и билдера тестов.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_listing_price')]
class MarketplaceListingPrice
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    // Та же длина, что у marketplace_listing и sales_fact: по этой
    // колонке они соединяются, и расхождение типов стоило бы приведения
    // в каждом запросе.
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Id]
    #[ORM\Column]
    private readonly \DateTimeImmutable $changedAt;

    /** Цена, выставленная продавцом, — то, из чего вычитается витринная. */
    #[ORM\Column(type: 'money_minor_amount')]
    private readonly int $priceMinor;

    /**
     * Зачёркнутая «цена до скидки». В расчёте СПП не участвует,
     * но приходит тем же ответом и рассказывает о скидке самого
     * продавца — без неё непонятно, от чего Ozon считает свою.
     */
    #[ORM\Column(type: 'money_minor_amount', nullable: true)]
    private readonly ?int $oldPriceMinor;

    // options: ['fixed' => true] — ADR-004 требует именно char(3).
    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private readonly string $currency;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $changedAt,
        Money $price,
        ?Money $oldPrice,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->marketplaceSku = $marketplaceSku;
        $this->changedAt = $changedAt;
        $this->priceMinor = $price->minorAmount();
        $this->oldPriceMinor = $oldPrice?->minorAmount();
        $this->currency = $price->currency();
    }

    public static function seen(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $changedAt,
        Money $price,
        ?Money $oldPrice,
    ): self {
        if (null !== $oldPrice && $oldPrice->currency() !== $price->currency()) {
            // Обе цены одной карточки в одной валюте; разные означали бы
            // ошибку разбора ответа, а не смешанный случай (ADR-004).
            throw new \InvalidArgumentException(\sprintf('Цена и цена до скидки обязаны быть в одной валюте, пришли %s и %s (ADR-004).', $price->currency(), $oldPrice->currency()));
        }

        return new self($companyId, $marketplaceAccountId, $marketplaceSku, $changedAt, $price, $oldPrice);
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplaceAccountId(): Uuid
    {
        return $this->marketplaceAccountId;
    }

    public function marketplaceSku(): string
    {
        return $this->marketplaceSku;
    }

    public function changedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function price(): Money
    {
        return Money::ofMinor($this->priceMinor, $this->currency);
    }

    public function oldPrice(): ?Money
    {
        return null === $this->oldPriceMinor ? null : Money::ofMinor($this->oldPriceMinor, $this->currency);
    }
}
