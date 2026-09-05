<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\Marketplace;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Одно подключение = один магазин/кабинет = один набор ключей (ADR-002).
 * Связь с компанией неизменяема: сеттера нет, только конструктор.
 *
 * credentialsCiphertext хранит уже зашифрованную строку — сама сущность
 * не знает о шифровании (Domain не зависит от Infrastructure); шифрует
 * и расшифровывает App\Identity\Infrastructure\Crypto\CredentialsCipher.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_account')]
#[ORM\UniqueConstraint(
    name: 'uq_marketplace_account_company_marketplace_external_shop',
    columns: ['company_id', 'marketplace', 'external_shop_id'],
)]
#[ORM\UniqueConstraint(
    name: 'uq_marketplace_account_marketplace_external_shop_active',
    columns: ['marketplace', 'external_shop_id'],
    options: ['where' => "((state)::text <> 'revoked'::text)"],
)]
#[ORM\Index(name: 'idx_marketplace_account_company_id', columns: ['company_id'])]
class MarketplaceAccount
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $companyId;

    #[ORM\Column(length: 32, enumType: Marketplace::class)]
    private readonly Marketplace $marketplace;

    #[ORM\Column(length: 255)]
    private readonly string $externalShopId;

    /**
     * Название магазина: пара Client-Id из цифр человеку ничего не говорит.
     * Правки нет намеренно (ADR-021) — правимое имя переводит сущность
     * во вторую строку таблиц ADR-011 и стоит версии и записи в журнал,
     * и платить эту цену надо в задаче про переименование.
     */
    #[ORM\Column(length: 255)]
    private readonly string $name;

    #[ORM\Column(type: 'text')]
    private string $credentialsCiphertext;

    #[ORM\Column(type: 'smallint')]
    private int $credentialsKeyVersion;

    #[ORM\Column(length: 16, enumType: MarketplaceAccountState::class)]
    private MarketplaceAccountState $state;

    /**
     * Оптимистическая блокировка (ADR-008, уточнение ADR-011): учётные
     * данные правятся человеком на месте, и без версии второй сохранивший
     * молча затирает первого. Поле ведёт Doctrine, приложение его только
     * отдаёт клиенту и принимает обратно.
     */
    // Тип указан явно: версионной колонке Doctrine не выводит его
    // из PHP-типа и падает на маппинге.
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    private function __construct(
        Uuid $id,
        Uuid $companyId,
        Marketplace $marketplace,
        string $name,
        string $externalShopId,
        string $credentialsCiphertext,
        int $credentialsKeyVersion,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->marketplace = $marketplace;
        $this->name = $name;
        $this->externalShopId = $externalShopId;
        $this->credentialsCiphertext = $credentialsCiphertext;
        $this->credentialsKeyVersion = $credentialsKeyVersion;
        $this->state = MarketplaceAccountState::Active;
        $this->createdAt = $createdAt;
    }

    public static function connect(
        Uuid $companyId,
        Marketplace $marketplace,
        string $name,
        string $externalShopId,
        string $credentialsCiphertext,
        int $credentialsKeyVersion,
    ): self {
        return new self(
            Uuid::v7(),
            $companyId,
            $marketplace,
            $name,
            $externalShopId,
            $credentialsCiphertext,
            $credentialsKeyVersion,
            new \DateTimeImmutable(),
        );
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function marketplace(): Marketplace
    {
        return $this->marketplace;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function externalShopId(): string
    {
        return $this->externalShopId;
    }

    public function credentialsCiphertext(): string
    {
        return $this->credentialsCiphertext;
    }

    public function credentialsKeyVersion(): int
    {
        return $this->credentialsKeyVersion;
    }

    public function state(): MarketplaceAccountState
    {
        return $this->state;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Клиент выпустил новый ключ в кабинете площадки и заменил его у нас
     * (ADR-007).
     *
     * Заодно возвращает подключение в работу: если оно стояло broken,
     * причина была ровно в этих учётных данных, и оставлять его сломанным
     * после замены значило бы требовать второго действия, которого клиент
     * не поймёт. Проверку нового ключа делает вызывающий сценарий — сюда
     * учётные данные приходят уже подтверждёнными площадкой.
     *
     * Отозванное подключение сюда не попадает: отзыв необратим
     * (ADR-011, признак append-only), и «оживить» его заменой ключа
     * нельзя — вызывающий обязан отказать раньше.
     */
    public function replaceCredentials(string $credentialsCiphertext, int $credentialsKeyVersion): void
    {
        if (MarketplaceAccountState::Revoked === $this->state) {
            throw new \DomainException('Отозванное подключение не оживляется заменой ключа (ADR-011).');
        }

        $this->credentialsCiphertext = $credentialsCiphertext;
        $this->credentialsKeyVersion = $credentialsKeyVersion;

        if (MarketplaceAccountState::Broken === $this->state) {
            $this->state = MarketplaceAccountState::Active;
        }
    }

    /**
     * Ответ площадки об отсутствии авторизации (ADR-007).
     *
     * **Боевой код сюда не ходит.** Переход выполняет
     * MarketplaceAccountRepository::markBrokenIfActive — условным UPDATE,
     * потому что у подключения две задачи в очереди (продажи и каталог)
     * и обе получают отказ одновременно: «прочитать, проверить, записать»
     * прошло бы у обеих, и клиент получил бы два письма об одном событии
     * (CLAUDE.md §4).
     *
     * Метод остаётся ради подготовки данных в тестах: состояние broken
     * нужно строить до вставки, иначе сырой UPDATE разойдётся
     * с ORM-кэшем и тест начнёт проверять не то состояние, что в базе.
     */
    public function markBroken(): void
    {
        $this->state = MarketplaceAccountState::Broken;
    }

    /**
     * Действие клиента (ADR-007). Данные подключения не удаляются.
     */
    public function revoke(): void
    {
        $this->state = MarketplaceAccountState::Revoked;
    }
}
