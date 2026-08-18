<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Infrastructure\Query;

use App\Shared\Domain\ValueObject\Money;

/**
 * Отслеживаемый артикул вместе с последним наблюдением, если оно есть.
 * readonly DTO, не сущность (CLAUDE.md §5).
 */
final readonly class TrackedSkuOverviewRow
{
    public function __construct(
        public string $marketplaceSku,
        /**
         * Кабинет, к которому привязано отслеживание. Нужен, чтобы
         * спросить цену именно этого кабинета: после переподключения
         * магазина в истории остаются строки обоих, и выбор без кабинета
         * дал бы правдоподобный, но неверный соинвест.
         */
        public string $marketplaceAccountId,
        public ?int $displayedPriceMinor,
        public ?string $currency,
        public ?\DateTimeImmutable $observedAt,
    ) {
    }

    /**
     * null — наблюдений по артикулу ещё не приходило. Это не «цена
     * ноль», а «расширение сюда ещё не дошло», и экран обязан различать
     * эти состояния.
     */
    public function displayedPrice(): ?Money
    {
        if (null === $this->displayedPriceMinor || null === $this->currency) {
            return null;
        }

        return Money::ofMinor($this->displayedPriceMinor, $this->currency);
    }
}
