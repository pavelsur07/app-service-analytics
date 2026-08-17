<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Артикул, за ценой которого следит расширение (ADR-014).
 *
 * Заводится действием человека — кликом в оверлей-панели, — а не
 * синхронизацией, поэтому суррогатный `UUIDv7`, как у себестоимости
 * в ADR-013, а не естественный ключ факт-таблицы из CLAUDE.md §2.
 * Естественный ключ здесь тоже есть, но живёт уникальным индексом:
 * он же и защита от второй строки на тот же артикул.
 *
 * **Ключ — (компания, артикул), без кабинета**, хотя кабинет в строке
 * и хранится. Продавец отслеживает товар, а не пару «товар в этом
 * кабинете», и кабинет в ключе означал бы вторую активную строку
 * на тот же артикул после переподключения магазина: список отдал бы
 * дубль, расширение обошло бы карточку дважды за цикл, а потолок
 * посчитал бы её за две. Кабинет при повторном включении обновляется
 * на текущий — будущие наблюдения должны уехать в живой кабинет,
 * а не в отозванный.
 *
 * Ссылки на сущности других модулей (`marketplace_account_id`,
 * `created_by_user_id`) — скалярные поля без `#[ManyToOne]`: связь
 * по идентификатору, не Doctrine-ассоциация (CLAUDE.md §6).
 *
 * `created_by_user_id` — ссылка на `User` чужого модуля, и §6 требует
 * индекс в той же миграции без оговорок про размер таблицы: составной,
 * `company_id` первым столбцом (§1). Оговорка у
 * `ExtensionToken::$revokedByUserId` сюда не переносится — там и токен,
 * и пользователь живут в Identity, то есть правило не срабатывает вовсе.
 *
 * ORM эту таблицу не пишет, хотя данные и редактирует человек
 * (CLAUDE.md §6). Причина не в характере данных, а в характере записи:
 * обе операции — условные переходы состояния («заведи или верни
 * в active», «останови, если была активна»), а условие обязано жить
 * внутри запроса, иначе два параллельных клика проходят проверку оба
 * (CLAUDE.md §4). Тот же приём и та же причина, что
 * у `ExtensionTokenRepository::revokeIfActive`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tracked_sku')]
#[ORM\UniqueConstraint(
    name: 'uq_tracked_sku_company_sku',
    columns: ['company_id', 'marketplace_sku'],
)]
#[ORM\Index(name: 'idx_tracked_sku_company_created_by', columns: ['company_id', 'created_by_user_id'])]
class TrackedSku
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $marketplaceAccountId;

    // Тот же тип и та же длина, что у marketplace_listing, sales_fact
    // и marketplace_listing_cost: по этой колонке они соединяются,
    // и расхождение типов стоило бы приведения в каждом запросе.
    #[ORM\Column(length: 64)]
    private readonly string $marketplaceSku;

    #[ORM\Column(length: 16, enumType: TrackedSkuStatus::class)]
    private TrackedSkuStatus $status;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $createdByUserId;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $stoppedAt = null;

    private function __construct(
        Uuid $id,
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        TrackedSkuStatus $status,
        \DateTimeImmutable $createdAt,
        Uuid $createdByUserId,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->marketplaceAccountId = $marketplaceAccountId;
        $this->marketplaceSku = $marketplaceSku;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->createdByUserId = $createdByUserId;
    }

    /**
     * $createdAt параметром, а не `new \DateTimeImmutable()` внутри:
     * билдер обязан уметь задать момент, не вычисляя его сам (ADR-005).
     */
    public static function startTracking(
        Uuid $companyId,
        Uuid $marketplaceAccountId,
        string $marketplaceSku,
        Uuid $createdByUserId,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            Uuid::v7(),
            $companyId,
            $marketplaceAccountId,
            $marketplaceSku,
            TrackedSkuStatus::Active,
            $createdAt,
            $createdByUserId,
        );
    }

    /**
     * Остановки как метода сущности здесь нет намеренно — по той же
     * причине, по которой её нет у `ExtensionToken` (ADR-010): «прочитать,
     * проверить, записать» два параллельных запроса проходят оба.
     * Остановка — условный UPDATE в самом запросе,
     * `TrackedSkuRepository::stopIfActive()`.
     */
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

    public function status(): TrackedSkuStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function createdByUserId(): Uuid
    {
        return $this->createdByUserId;
    }

    public function stoppedAt(): ?\DateTimeImmutable
    {
        return $this->stoppedAt;
    }
}
