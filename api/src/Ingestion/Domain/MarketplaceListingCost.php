<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Себестоимость единицы товара, действующая с указанной даты (ADR-013).
 *
 * Привязана к карточке площадки, а не к товару продавца: накладные
 * расходы до склада у Ozon и WB свои, и одно число на две площадки было
 * бы неправдой у обеих. Артикул продавца в ключ не входит — селлер
 * переименовывает его сам, и история цен отвязалась бы при первом же
 * переименовании.
 *
 * **Битемпоральна.** `effectiveFrom` — с какой бизнес-даты цена верна;
 * `recordedAt` — когда мы об этом узнали. Два поля разводят два разных
 * события: новая закупка по новой цене заводит новую строку и прошлое
 * не трогает, а исправление ошибки правит существующую и обязано
 * изменить уже показанную прибыль.
 *
 * **Правится на месте, и потому требует и версии, и журнала.** Версия —
 * оптимистическая блокировка (ADR-008): два человека, открывшие форму
 * одновременно, не должны молча затирать правку друг друга. Журнал
 * с «было и стало» (ADR-011) — потому что при правке на месте прежнее
 * значение исчезает, а вопрос «почему июль стал другим» задаётся
 * позже и требует ответа.
 *
 * Отсюда прямой ответ на вопрос, который ADR-013 оставил читателю:
 * **историей записи (то, что было известно на момент T) служит
 * аудит-журнал, а не вторая строка этой таблицы.** Хранить версии
 * строками означало бы завести признак «какая из них действующая»
 * и согласовывать его с уникальным индексом; журнал же обязателен
 * для себестоимости по правилам в любом случае, и дублировать его
 * второй конструкцией незачем. Цена решения названа прямо: сводный
 * отчёт «как выглядел июль до правок» из журнала собирается разбором
 * текста, а не запросом, — и если такой отчёт понадобится, это повод
 * пересмотреть хранение, а не дописывать разбор.
 *
 * **Дата окончания действия не хранится.** Она выводится из следующей
 * записи: вторая дата рядом с первой неизбежно рассинхронизируется —
 * типовая поломка интервальных таблиц.
 *
 * **Метод учёта записан в строке.** Сегодня он всегда `manual`, и это
 * не задел на будущее, а защита прошлого: приход партионного учёта
 * не должен задним числом переосмыслить числа, введённые руками.
 *
 * Пишется ORM (CLAUDE.md §6): данные редактирует человек, не
 * синхронизация. Суррогатный ключ, а не составной естественный: запрет
 * суррогата из §2 написан для факт-таблиц.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_listing_cost')]
#[ORM\UniqueConstraint(
    name: 'uq_marketplace_listing_cost_effective_from',
    columns: ['company_id', 'marketplace_account_id', 'marketplace_sku', 'effective_from'],
)]
class MarketplaceListingCost
{
    /**
     * Метод учёта себестоимости. Ручная цена за штуку — единственный
     * сегодня; партионный учёт (FIFO) отвергнут как первый шаг, потому
     * что без достоверных входящих остатков он неверен незаметно
     * (ADR-013).
     */
    public const string MethodManual = 'manual';

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    // Тот же тип и то же имя, что у marketplace_listing и sales_fact:
    // по этой колонке они соединяются, и расхождение типов стоило бы
    // приведения в каждом запросе.
    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column(type: 'date_immutable')]
    private readonly \DateTimeImmutable $effectiveFrom;

    #[ORM\Column(type: 'money_minor_amount')]
    private int $unitCostMinor;

    // options: ['fixed' => true] — ADR-004 требует именно char(3).
    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private string $currency;

    #[ORM\Column(length: 32)]
    private readonly string $method;

    #[ORM\Column]
    private readonly \DateTimeImmutable $recordedAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    private function __construct(
        Uuid $id,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $effectiveFrom,
        Money $unitCost,
        string $method,
        \DateTimeImmutable $recordedAt,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->marketplaceSku = $marketplaceSku;
        $this->effectiveFrom = $effectiveFrom;
        $this->unitCostMinor = $unitCost->minorAmount();
        $this->currency = $unitCost->currency();
        $this->method = $method;
        $this->recordedAt = $recordedAt;
        $this->updatedAt = $recordedAt;
    }

    /**
     * Новая цена, действующая с даты. Прошлое не трогает: у более ранних
     * дней остаётся своя запись.
     */
    public static function pricedFrom(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        \DateTimeImmutable $effectiveFrom,
        Money $unitCost,
        \DateTimeImmutable $recordedAt,
    ): self {
        if ($unitCost->minorAmount() < 0) {
            // Отрицательная закупка — не «скидка», а опечатка. Расходы
            // площадки приходят со своим знаком и живут в другой
            // таблице (ADR-012); здесь человек вводит, во что товар
            // обошёлся, и это величина неотрицательная.
            throw new \InvalidArgumentException('Себестоимость не может быть отрицательной.');
        }

        return new self(
            Uuid::v7(),
            $companyId,
            $marketplaceAccountId,
            $marketplaceSku,
            $effectiveFrom->setTime(0, 0),
            $unitCost,
            self::MethodManual,
            $recordedAt,
        );
    }

    /**
     * Исправление: то, что записали, оказалось неверным. Меняет уже
     * показанную прибыль — в отличие от новой цены с новой даты,
     * и потому это отдельная операция, а не та же с другим аргументом.
     *
     * Валюту не меняет: смена валюты у существующей записи означала бы
     * пересчёт по курсу, которого ADR-004 не допускает молча. Нужна
     * другая валюта — это новая запись.
     */
    public function correctTo(Money $unitCost, \DateTimeImmutable $correctedAt): void
    {
        if ($unitCost->currency() !== $this->currency) {
            throw new \InvalidArgumentException(\sprintf('Нельзя сменить валюту себестоимости с %s на %s исправлением (ADR-004).', $this->currency, $unitCost->currency()));
        }

        if ($unitCost->minorAmount() < 0) {
            throw new \InvalidArgumentException('Себестоимость не может быть отрицательной.');
        }

        $this->unitCostMinor = $unitCost->minorAmount();
        $this->updatedAt = $correctedAt;
    }

    public function id(): Uuid
    {
        return $this->id;
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

    public function effectiveFrom(): \DateTimeImmutable
    {
        return $this->effectiveFrom;
    }

    public function unitCost(): Money
    {
        return Money::ofMinor($this->unitCostMinor, $this->currency);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function recordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function version(): int
    {
        return $this->version;
    }
}
