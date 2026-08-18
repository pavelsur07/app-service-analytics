<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Ingestion\Domain\MarketplaceListingPriceRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\MarketplaceListingPriceBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * ADR-015: строка появляется только при изменении цены. Без этого
 * синхронизация каждые полчаса набивала бы три тысячи одинаковых строк
 * в сутки на компанию — и история изменений превратилась бы в журнал
 * опросов.
 */
final class DoctrineMarketplaceListingPriceWriterTest extends KernelTestCase
{
    private Uuid $companyId;
    private Uuid $accountId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->companyId = Uuid::v7();
        $this->accountId = Uuid::v7();
    }

    public function testRepeatedSyncWithTheSamePriceAddsNothing(): void
    {
        $this->record('2026-08-18 09:00:00', 253_700);
        $this->record('2026-08-18 09:30:00', 253_700);
        $this->record('2026-08-18 10:00:00', 253_700);

        self::assertSame(1, $this->countRows(), 'цена не менялась — строка одна');
    }

    public function testTheSameResponseProcessedTwiceAddsOneRow(): void
    {
        // Ключ строки содержит raw-документ, а тот дедуплицируется
        // по содержимому (ADR-006): повторная обработка того же ответа
        // площадки — хоть ретраем, хоть параллельным прогоном — упирается
        // в уникальный индекс, а не в проверку существования
        // (CLAUDE.md §4).
        $document = Uuid::v7();
        $this->record('2026-08-18 09:00:00', 253_700, null, $document);
        $this->record('2026-08-18 09:00:07', 199_900, null, $document);

        self::assertSame(1, $this->countRows());
        self::assertSame([253_700], $this->prices());
    }

    public function testChangedPriceAddsARowAndKeepsTheOldOne(): void
    {
        $this->record('2026-08-18 09:00:00', 253_700);
        $this->record('2026-08-18 09:30:00', 199_900);

        // Прошлое не переписывается: СПП за вчера считается против
        // вчерашней цены.
        self::assertSame(2, $this->countRows());
        self::assertSame([199_900, 253_700], $this->prices());
    }

    public function testPriceReturningToTheOldValueIsANewRow(): void
    {
        $this->record('2026-08-18 09:00:00', 253_700);
        $this->record('2026-08-18 09:30:00', 199_900);
        $this->record('2026-08-18 10:00:00', 253_700);

        // Сравнивается последняя строка, а не любая из прошлых: цена
        // вернулась к прежней, и это новое событие, а не дубль.
        self::assertSame(3, $this->countRows());
    }

    public function testAppearingOldPriceIsAChange(): void
    {
        $this->record('2026-08-18 09:00:00', 253_700);
        $this->record('2026-08-18 09:30:00', 253_700, 399_900);

        // Продавец объявил скидку: сама цена та же, зачёркнутая
        // появилась. Сравнение через IS DISTINCT FROM — обычное `<>`
        // с NULL вернуло бы NULL, и изменение потерялось бы.
        self::assertSame(2, $this->countRows());
    }

    public function testAnotherCompanyWithTheSameSkuIsIndependent(): void
    {
        $this->record('2026-08-18 09:00:00', 253_700);

        $foreign = Uuid::v7();
        $this->repository()->recordChanged($foreign->toRfc4122(), [
            MarketplaceListingPriceBuilder::aMarketplaceListingPrice()
                ->withCompanyId($foreign)
                ->withMarketplaceAccountId($this->accountId)
                ->withChangedAt(new \DateTimeImmutable('2026-08-18 09:30:00'))
                ->withPrice(Money::ofMinor(253_700, 'RUB'))
                ->build(),
        ]);

        // Артикулы площадки общие для всех продавцов: чужая цена
        // не должна выглядеть как «наша не изменилась».
        self::assertSame(1, $this->countRows());
        self::assertSame(1, $this->countRows($foreign));
    }

    private function record(string $at, int $priceMinor, ?int $oldPriceMinor = null, ?Uuid $rawDocumentId = null): void
    {
        $this->repository()->recordChanged($this->companyId->toRfc4122(), [
            MarketplaceListingPriceBuilder::aMarketplaceListingPrice()
                ->withCompanyId($this->companyId)
                ->withMarketplaceAccountId($this->accountId)
                ->withChangedAt(new \DateTimeImmutable($at))
                ->withRawDocumentId($rawDocumentId ?? Uuid::v7())
                ->withPrice(
                    Money::ofMinor($priceMinor, 'RUB'),
                    null === $oldPriceMinor ? null : Money::ofMinor($oldPriceMinor, 'RUB'),
                )
                ->build(),
        ]);
    }

    private function countRows(?Uuid $companyId = null): int
    {
        $count = $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM marketplace_listing_price WHERE company_id = :companyId',
            ['companyId' => ($companyId ?? $this->companyId)->toRfc4122()],
        );
        self::assertTrue(\is_int($count) || \is_string($count));

        return (int) $count;
    }

    /**
     * @return list<int>
     */
    private function prices(): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT price_minor FROM marketplace_listing_price WHERE company_id = :companyId ORDER BY changed_at DESC',
            ['companyId' => $this->companyId->toRfc4122()],
        );

        return array_map(
            static function (array $row): int {
                $value = $row['price_minor'];
                self::assertTrue(\is_int($value) || \is_string($value));

                return (int) $value;
            },
            $rows,
        );
    }

    private function repository(): MarketplaceListingPriceRepository
    {
        /** @var MarketplaceListingPriceRepository $repository */
        $repository = static::getContainer()->get(MarketplaceListingPriceRepository::class);

        return $repository;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }
}
