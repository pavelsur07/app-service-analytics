<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Identity\Domain\Company;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\User;
use App\PriceMonitoring\Domain\TrackedSku;
use App\PriceMonitoring\Domain\TrackedSkuRepository;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-005: валидные умолчания, неизменяем, каждый метод возвращает
 * новый экземпляр.
 *
 * Связанные Company/User/MarketplaceAccount не создаёт сам, в отличие
 * от большинства билдеров: отслеживание всегда заводится внутри уже
 * существующего окружения (компания, её подключение, её участник),
 * и билдер, порождающий это окружение молча, прятал бы от теста ровно
 * те связи, изоляцию которых он проверяет. Не задано — берётся
 * случайный UUID, и такая строка заведомо ничья.
 *
 * `stopped` через билдер получить нельзя: остановка — условный UPDATE
 * в репозитории (CLAUDE.md §4), и второй путь к тому же состоянию,
 * минуя проверяемый, сделал бы тест зелёным при сломанном переходе.
 * Нужна остановленная запись — `track()`, затем `stopIfActive()`.
 */
final class TrackedSkuBuilder
{
    private ?Uuid $companyId = null;
    private ?Uuid $marketplaceAccountId = null;
    private string $marketplaceSku = '100000001';
    private ?Uuid $createdByUserId = null;
    private ?\DateTimeImmutable $createdAt = null;

    private function __construct()
    {
    }

    public static function aTrackedSku(): self
    {
        return new self();
    }

    public function withCompany(Company $company): self
    {
        $clone = clone $this;
        $clone->companyId = $company->id();

        return $clone;
    }

    public function withCompanyId(Uuid $companyId): self
    {
        $clone = clone $this;
        $clone->companyId = $companyId;

        return $clone;
    }

    public function withMarketplaceAccount(MarketplaceAccount $account): self
    {
        $clone = clone $this;
        $clone->marketplaceAccountId = $account->id();

        return $clone;
    }

    public function withMarketplaceSku(string $marketplaceSku): self
    {
        $clone = clone $this;
        $clone->marketplaceSku = $marketplaceSku;

        return $clone;
    }

    public function withCreatedBy(User $user): self
    {
        $clone = clone $this;
        $clone->createdByUserId = $user->id();

        return $clone;
    }

    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function build(): TrackedSku
    {
        return TrackedSku::startTracking(
            companyId: $this->companyId ?? Uuid::v7(),
            marketplaceAccountId: $this->marketplaceAccountId ?? Uuid::v7(),
            marketplaceSku: $this->marketplaceSku,
            createdByUserId: $this->createdByUserId ?? Uuid::v7(),
            createdAt: $this->createdAt ?? new \DateTimeImmutable(),
        );
    }

    public function persistWith(TrackedSkuRepository $trackedSkus): TrackedSku
    {
        $trackedSku = $this->build();
        $trackedSkus->track($trackedSku);

        return $trackedSku;
    }
}
