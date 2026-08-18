<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Снимок двух цен карточки Ozon, снятый расширением (ADR-014).
 *
 * Факт, а не редактируемая человеком запись: после вставки не меняется
 * никогда. Поэтому ключ естественный и суррогата нет (CLAUDE.md §2),
 * а пишется таблица через DBAL, не ORM (§6).
 *
 * **Ключ — (компания, кабинет, артикул, момент снимка).** Он же и ключ
 * идемпотентности: момент фиксируется в браузере в тот миг, когда цена
 * прочитана, и при повторной отправке после сетевого сбоя приезжает тот
 * же самый. Отдельного поля под идемпотентность нет и не нужно —
 * CLAUDE.md §4: «где у результата есть естественный ключ, идемпотентность
 * обеспечивается уникальным индексом на самих данных».
 *
 * **Двух времён два, и они разные.** `observed_at` — когда цену увидели,
 * его сообщает клиент; `received_at` — когда строка доехала до нас, его
 * ставит сервер. Складывать их в одно поле нельзя: часы на машине
 * продавца могут врать, и обещание интерфейса «последнее наблюдение:
 * N назад» тогда врало бы вместе с ними. Это те же два времени, что
 * ADR-006 требует от факт-строки; третьего («последнее обновление»)
 * здесь нет, потому что строка не обновляется.
 *
 * **Ссылки на raw-документ нет, и это не пропуск.** Raw-слой ADR-006
 * существует потому, что площадка перевыпускает отчёты и факт приходится
 * пересчитывать из сырья. Здесь сырьё и есть сама строка: выше неё нет
 * ничего, из чего её можно было бы вывести заново, а хранить копию
 * пятиполевого JSON рядом со строкой означало бы держать одни и те же
 * данные дважды.
 *
 * **Цена продавца здесь не хранится, и прислать её клиент не может**
 * (ADR-015). Спайк по живой карточке показал, что на странице её нет
 * вовсе: продавец видит её в кабинете, покупатель — никогда. Зато она
 * приходит в ответе `/v3/product/info/list`, за которым синхронизация
 * каталога и так ходит, и живёт историей в `marketplace_listing_price`.
 *
 * Поэтому наблюдение несёт ровно одно число — то единственное, чего
 * не отдаёт ни один API площадки. СПП считается при чтении как разница
 * цены продавца, действовавшей на момент снимка, и этой витринной
 * (ADR-013 §4: не хранить то, что выводится).
 *
 * `captured_by_user_id` — ссылка на `User` чужого модуля, и §6 требует
 * для такой ссылки индекс. Здесь его сознательно нет: по этой колонке
 * не фильтруют и не соединяют ни в одном запросе, а таблица, в отличие
 * от `tracked_sku`, растёт неограниченно — полсотни артикулов дают около
 * миллиона строк в год на компанию, и неиспользуемый индекс оплачивался
 * бы на каждой вставке. Это тот же принцип, который CLAUDE.md §1
 * формулирует прямо: «индекс следует за запросом, а не за таблицей».
 * Расхождение с буквой §6 вынесено владельцу отдельным вопросом,
 * а не исправлено молча в обе стороны.
 */
#[ORM\Entity]
#[ORM\Table(name: 'price_observation')]
class PriceObservation
{
    /**
     * Как снято. Сегодня всегда `extension` — и это не задел на будущее,
     * а защита прошлого: появится другой способ сбора, и строки, снятые
     * с карточки браузером, не должны задним числом смешаться с ним.
     * Тот же приём, что у `MarketplaceListingCost::MethodManual`.
     */
    public const string SourceExtension = 'extension';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Id]
    #[ORM\Column]
    private readonly \DateTimeImmutable $observedAt;

    /** Витринная цена Ozon — до скидки банка и платёжной системы. */
    #[ORM\Column(type: 'money_minor_amount')]
    private readonly int $displayedPriceMinor;

    /**
     * Остаток от прежней схемы, где расширение присылало две цены
     * (ADR-015 отменил вторую). Колонка ещё существует и всегда NULL:
     * удаление идёт в два шага, как требует правило совместимых
     * изменений на факт-таблицах.
     *
     * Одношаговое удаление сломало бы выкладку в любом порядке:
     * миграция вперёд кода — прежний writer пишет в исчезнувшую
     * колонку; код вперёд миграции — новый writer не заполняет
     * NOT NULL. Снятие NOT NULL совместимо с обоими, и окна
     * несовместимости не остаётся вовсе.
     *
     * ponytail: следующим изменением, когда новый код везде, — вместе
     * с колонкой. Держать этот остаток дольше одного релиза незачем.
     */
    #[ORM\Column(type: 'money_minor_amount', nullable: true)]
    private readonly ?int $sellerPriceMinor;

    // options: ['fixed' => true] — ADR-004 требует именно char(3).
    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private readonly string $currency;

    #[ORM\Column(length: 32)]
    private readonly string $source;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $capturedByUserId;

    /** Версия сборки расширения — по ней читаются массовые пропуски. */
    #[ORM\Column(length: 32)]
    private readonly string $extensionVersion;

    #[ORM\Column]
    private readonly \DateTimeImmutable $receivedAt;

    private function __construct(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $observedAt,
        Money $displayedPrice,
        string $source,
        Uuid $capturedByUserId,
        string $extensionVersion,
        \DateTimeImmutable $receivedAt,
    ) {
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->marketplaceSku = $marketplaceSku;
        $this->observedAt = $observedAt;
        $this->displayedPriceMinor = $displayedPrice->minorAmount();
        $this->sellerPriceMinor = null;
        $this->currency = $displayedPrice->currency();
        $this->source = $source;
        $this->capturedByUserId = $capturedByUserId;
        $this->extensionVersion = $extensionVersion;
        $this->receivedAt = $receivedAt;
    }

    /**
     * Кабинет берётся из строки отслеживания — там же, где проверяется
     * само право прислать наблюдение, — и приходит сюда уже готовым.
     * Клиент его не сообщает и сообщить не может: расширение знает
     * со страницы только артикул.
     */
    public static function captured(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $observedAt,
        Money $displayedPrice,
        Uuid $capturedByUserId,
        string $extensionVersion,
        \DateTimeImmutable $receivedAt,
    ): self {
        return new self(
            $companyId,
            $marketplaceAccountId,
            $marketplaceSku,
            $observedAt,
            $displayedPrice,
            self::SourceExtension,
            $capturedByUserId,
            $extensionVersion,
            $receivedAt,
        );
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

    public function observedAt(): \DateTimeImmutable
    {
        return $this->observedAt;
    }

    public function displayedPrice(): Money
    {
        return Money::ofMinor($this->displayedPriceMinor, $this->currency);
    }

    public function source(): string
    {
        return $this->source;
    }

    public function capturedByUserId(): Uuid
    {
        return $this->capturedByUserId;
    }

    public function extensionVersion(): string
    {
        return $this->extensionVersion;
    }

    public function receivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }
}
