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

    #[ORM\Column(type: 'text')]
    private string $credentialsCiphertext;

    #[ORM\Column(type: 'smallint')]
    private int $credentialsKeyVersion;

    #[ORM\Column(length: 16, enumType: MarketplaceAccountState::class)]
    private MarketplaceAccountState $state;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    private function __construct(
        Uuid $id,
        Uuid $companyId,
        Marketplace $marketplace,
        string $externalShopId,
        string $credentialsCiphertext,
        int $credentialsKeyVersion,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->marketplace = $marketplace;
        $this->externalShopId = $externalShopId;
        $this->credentialsCiphertext = $credentialsCiphertext;
        $this->credentialsKeyVersion = $credentialsKeyVersion;
        $this->state = MarketplaceAccountState::Active;
        $this->createdAt = $createdAt;
    }

    public static function connect(
        Uuid $companyId,
        Marketplace $marketplace,
        string $externalShopId,
        string $credentialsCiphertext,
        int $credentialsKeyVersion,
    ): self {
        return new self(
            Uuid::v7(),
            $companyId,
            $marketplace,
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

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Ответ площадки об отсутствии авторизации (ADR-007). Синхронизация
     * останавливается, уведомление клиенту — забота вызывающего кода,
     * не этого метода.
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
