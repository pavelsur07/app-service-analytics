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
 * Кроме артикула площадки хранятся артикул продавца и наименование.
 * Появились они тогда, когда стало где показывать: экран ввода
 * себестоимости работает с карточками, а `1402861293` — не то, в чём
 * селлер узнаёт свой товар.
 *
 * **Изменяемые колонки здесь есть, и это меняет правила записи.**
 * Раньше их не было ни одной, и писатель обходился `DO NOTHING`.
 * Артикул и имя селлер правит в кабинете, поэтому запись стала
 * `DO UPDATE` — но только при фактическом изменении значения,
 * тем же приёмом, что у sales_fact с его `WHERE row_hash IS DISTINCT
 * FROM`. Повторный прогон обработчика на том же ответе площадки
 * по-прежнему не меняет ни строки (CLAUDE.md §4).
 *
 * Отличие от удалённой когда-то `last_seen_at` ровно в этом: та менялась
 * при каждом прогоне независимо от данных, а имя — только когда его
 * поменяли на площадке. Признаком ухода товара она быть перестала
 * (writer сравнивает с самой выгрузкой), и читать её стало некому:
 * «когда каталог синхронизировался в прошлый раз» отвечает raw-слой.
 *
 * `first_seen_at` не обновляется никогда: товар, пришедший снова,
 * не становится новым.
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

    /**
     * Артикул продавца — то, чем товар называет сам селлер. Приходит
     * тем же ответом, что и sku, и в ключ не входит намеренно: селлер
     * переименовывает артикул сам, и будь он в ключе, история цен
     * отвязалась бы при первом переименовании. Длина как у соседней
     * marketplace_sku; Ozon допускает до 50 символов.
     *
     * Nullable, потому что до первой синхронизации после выкладки строки
     * его не имеют, а придумывать им пустую строку значило бы сделать
     * «не знаем» неотличимым от «пусто».
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $offerId;

    /**
     * Наименование карточки. Приходит вторым запросом
     * (v3/product/info/list), в v3/product/list его нет, — поэтому
     * отстать от артикула на тик оно может, и nullable здесь по той же
     * причине. Ozon допускает до 500 символов.
     */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $name;

    #[ORM\Column]
    private readonly \DateTimeImmutable $firstSeenAt;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        ?string $offerId,
        ?string $name,
        \DateTimeImmutable $firstSeenAt,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->marketplaceSku = $marketplaceSku;
        $this->offerId = $offerId;
        $this->name = $name;
        $this->firstSeenAt = $firstSeenAt;
    }

    public static function seen(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        ?string $offerId,
        ?string $name,
        \DateTimeImmutable $seenAt,
    ): self {
        return new self($companyId, $marketplaceAccountId, $marketplaceSku, $offerId, $name, $seenAt);
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

    public function offerId(): ?string
    {
        return $this->offerId;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function firstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }
}
