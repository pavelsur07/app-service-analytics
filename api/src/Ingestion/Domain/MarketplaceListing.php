<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Товар, выставленный на площадке, — каталог подключения.
 *
 * Зачем отдельно от `sales_fact`: до сих пор «свои артикулы» выводились
 * из продаж, и товар без единой продажи расширение считало чужим.
 * Клиент с новинками видел оверлей, который «работает через раз»,
 * и объяснить это было нечем.
 *
 * Хранится только артикул и момент, когда товар впервые увиделся.
 * Название и прочее придут, когда их будет где показывать: это
 * не факт-таблица с миллионами строк, добавить колонку позже дёшево
 * (CLAUDE.md, «не абстрагировать до второго случая»).
 *
 * Изменяемых колонок нет ни одной, и это осознанно. Была `last_seen_at`,
 * но признаком ухода товара она быть перестала (writer сравнивает
 * с самой выгрузкой), а читать её стало некому: «когда каталог
 * синхронизировался в прошлый раз» отвечает raw-слой. Оставленная,
 * она означала бы, что повторный прогон обработчика на том же ответе
 * площадки меняет строку, — ровно то, чего CLAUDE.md §4 не допускает
 * и чего избегает sales_fact своим `WHERE row_hash IS DISTINCT FROM`.
 *
 * Ключ естественный, без суррогата: `(company_id, marketplace_account_id,
 * marketplace_sku)`. Артикул уникален в пределах подключения, а не
 * площадки вообще — один и тот же товар может быть у двух продавцов,
 * и company_id первым столбцом (CLAUDE.md §1).
 *
 * Не пишется ORM (persist/flush): наполняется синхронизацией, не
 * человеком, — запись идёт DBAL-апсертом (CLAUDE.md §6) через
 * DoctrineMarketplaceListingWriter. Класс существует для
 * migrations:diff/schema:validate/Builder тестов.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_listing')]
class MarketplaceListing
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    // Тот же тип и то же имя, что у sales_fact.marketplace_sku:
    // объединяются они по этой колонке, и расхождение типов стоило бы
    // приведения в каждом запросе.
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column]
    private readonly \DateTimeImmutable $firstSeenAt;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $firstSeenAt,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->marketplaceSku = $marketplaceSku;
        $this->firstSeenAt = $firstSeenAt;
    }

    public static function seen(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $seenAt,
    ): self {
        return new self($companyId, $marketplaceAccountId, $marketplaceSku, $seenAt);
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

    public function firstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }
}
