<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\MarketplaceAccount;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `updated_at` подключения — версия ключей для сервисов вне монолита
 * (ADR-043 п. 4): после отказа Ozon сервис ждёт нового значения, а не
 * повторяет запросы старым ключом. Переход, забывший его сдвинуть,
 * оставил бы сервис ждать вечно или принимать новый ключ за отказанный.
 */
final class MarketplaceAccountUpdatedAtTest extends TestCase
{
    private const string LONG_AGO = '2026-01-01 00:00:00';

    public function testFreshConnectionIsUpdatedAtTheMomentOfConnecting(): void
    {
        $connectedAt = new \DateTimeImmutable(self::LONG_AGO);

        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withConnectedAt($connectedAt)->build();

        self::assertEquals($connectedAt, $account->updatedAt());
    }

    /**
     * @return iterable<string, array{\Closure(MarketplaceAccount): void}>
     */
    public static function transitions(): iterable
    {
        yield 'replace credentials' => [static fn (MarketplaceAccount $a) => $a->replaceCredentials('new-ciphertext', 1)];
        yield 'connect advertising' => [static fn (MarketplaceAccount $a) => $a->connectAdvertising('new-ciphertext', 1)];
        yield 'mark broken' => [static fn (MarketplaceAccount $a) => $a->markBroken()];
        yield 'revoke' => [static fn (MarketplaceAccount $a) => $a->revoke()];
    }

    /**
     * @param \Closure(MarketplaceAccount): void $transition
     */
    #[DataProvider('transitions')]
    public function testEveryChangeOfKeysOrStateMovesUpdatedAt(\Closure $transition): void
    {
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withConnectedAt(new \DateTimeImmutable(self::LONG_AGO))->build();

        $transition($account);

        self::assertGreaterThan(new \DateTimeImmutable(self::LONG_AGO), $account->updatedAt());
    }

    /**
     * Колонка хранит целые секунды: две замены ключа подряд обязаны дать
     * разные значения и в этом виде, иначе вторую сервис примет за первую.
     */
    public function testConsecutiveChangesWithinOneSecondStayDistinct(): void
    {
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withConnectedAt(new \DateTimeImmutable(self::LONG_AGO))->build();

        $versions = [];
        for ($i = 0; $i < 3; ++$i) {
            $account->replaceCredentials('ciphertext-'.$i, 1);
            $versions[] = $account->updatedAt()->format('Y-m-d H:i:s');
        }

        self::assertSame(array_unique($versions), $versions);
        $sorted = $versions;
        sort($sorted);
        self::assertSame($sorted, $versions);
    }
}
