<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Ingestion\Application\BuildUnitEconomicsAction;
use App\Ingestion\Application\UnitEconomicsReport;
use App\Ingestion\Domain\MarketplaceListingCost;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingCostRepository;
use App\Ingestion\Infrastructure\Query\UnitEconomicsDirection;
use App\Ingestion\Infrastructure\Query\UnitEconomicsSort;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceListingCostBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Себестоимость в расчёте прибыли (ADR-013).
 *
 * Обязательное покрытие ADR-005: денежная арифметика и изоляция между
 * компаниями. Главное здесь — что цена берётся на бизнес-дату каждой
 * продажи, а не одна на период: иначе августовская поставка переписала
 * бы прибыль за июль, ровно то, ради предотвращения чего ввод и разделён
 * на две операции.
 */
final class UnitEconomicsCostTest extends KernelTestCase
{
    private const string SKU = '111';
    private const string ACCOUNT_ID = '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b';

    public function testProfitIsMarginMinusCostOfWhatWasSold(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->cost($container, $company, '2026-07-01', 42_000);
        $this->sale($container, $company, '2026-07-05', 2, 274_700, -126_362);

        $sku = $this->report($container, $company)->skus[0];

        // Две штуки по 420 ₽ — себестоимость 840 ₽, и знак у неё
        // отрицательный, как у комиссии: итог везде складывается.
        self::assertSame(-84_000, $sku->costTotalMinor);
        self::assertSame(274_700 - 126_362, $sku->marginMinor);
        self::assertSame(274_700 - 126_362 - 84_000, $sku->profitMinor);
        self::assertSame(0, $sku->quantityWithoutCost);
    }

    public function testEachSaleTakesThePriceOfItsOwnDay(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Июльская закупка по 420, августовская по 510.
        $this->cost($container, $company, '2026-07-01', 42_000);
        $this->cost($container, $company, '2026-08-01', 51_000);

        $this->sale($container, $company, '2026-07-05', 1, 100_000, 0, 'july');
        $this->sale($container, $company, '2026-08-05', 1, 100_000, 0, 'august');

        $sku = $this->report($container, $company, new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-08-31'))->skus[0];

        // 420 + 510, а не 510 + 510: товар, проданный в июле, стоил
        // столько, сколько стоил, и августовская поставка не имеет
        // права переписать прибыль за июль.
        self::assertSame(-93_000, $sku->costTotalMinor);
    }

    public function testPriceStartingLaterDoesNotApplyToEarlierSales(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Цена заведена с августа, продажа была в июле — на неё цены нет.
        $this->cost($container, $company, '2026-08-01', 51_000);
        $this->sale($container, $company, '2026-07-05', 3, 100_000, 0);

        $sku = $this->report($container, $company, new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'))->skus[0];

        self::assertSame(3, $sku->quantityWithoutCost);
        self::assertNull($sku->profitMinor);
    }

    public function testProfitIsUnknownWhenPartOfTheSalesHasNoPrice(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->cost($container, $company, '2026-07-10', 42_000);
        $this->sale($container, $company, '2026-07-05', 1, 100_000, 0, 'before');
        $this->sale($container, $company, '2026-07-15', 1, 100_000, 0, 'after');

        $sku = $this->report($container, $company, new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2026-07-31'))->skus[0];

        // Ни «почти прибыль», ни прибыль по той части, где цена есть:
        // обе выглядели бы настоящим числом, а ошибались бы на всю
        // незаданную закупку (ADR-013).
        self::assertSame(1, $sku->quantityWithoutCost);
        self::assertNull($sku->profitMinor);
        // Себестоимость известной части при этом посчитана — она нужна,
        // чтобы объяснить клиенту, чего не хватает.
        self::assertSame(-42_000, $sku->costTotalMinor);
    }

    public function testCostOfAnotherCompanyIsNotUsed(): void
    {
        $container = $this->bootedContainer();
        $ours = $this->company($container);
        $theirs = $this->company($container);

        // Тот же артикул, то же подключение, чужая компания.
        $this->cost($container, $theirs, '2026-07-01', 42_000);
        $this->sale($container, $ours, '2026-07-05', 1, 100_000, 0);

        $sku = $this->report($container, $ours)->skus[0];

        self::assertSame(0, $sku->costTotalMinor);
        self::assertSame(1, $sku->quantityWithoutCost);
        self::assertNull($sku->profitMinor);
    }

    public function testCostOfAnotherAccountIsNotUsed(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Цена заведена у другого подключения той же компании: накладные
        // до склада у площадок разные, и цена одной не годится другой
        // (ADR-013).
        $this->cost($container, $company, '2026-07-01', 42_000, Uuid::v7());
        $this->sale($container, $company, '2026-07-05', 1, 100_000, 0);

        $sku = $this->report($container, $company)->skus[0];

        self::assertSame(0, $sku->costTotalMinor);
        self::assertNull($sku->profitMinor);
    }

    public function testCorrectionOfThePeriodIsNamed(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $cost = $this->cost($container, $company, '2026-07-01', 42_000);
        $this->sale($container, $company, '2026-07-05', 1, 100_000, 0);

        self::assertNull($this->report($container, $company)->skus[0]->costCorrectedAt);

        // Прибыль считается при чтении (ADR-013), поэтому отчёт
        // за прошедший месяц меняется под руками. Раз данные
        // битемпоральны, экран обязан это назвать, а не молча показать
        // другую цифру.
        $cost->correctTo(Money::ofMinor(51_000, 'RUB'), new \DateTimeImmutable('+1 hour'));
        $this->entityManager($container)->flush();

        $sku = $this->report($container, $company)->skus[0];

        self::assertNotNull($sku->costCorrectedAt);
        self::assertSame(-51_000, $sku->costTotalMinor);
    }

    private function entityManager(ContainerInterface $container): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        return $entityManager;
    }

    private function report(
        ContainerInterface $container,
        Company $company,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): UnitEconomicsReport {
        /** @var BuildUnitEconomicsAction $action */
        $action = $container->get(BuildUnitEconomicsAction::class);

        return ($action)(
            $company->id()->toRfc4122(),
            $from ?? new \DateTimeImmutable('2026-07-01'),
            $to ?? new \DateTimeImmutable('2026-07-31'),
            50,
            31,
            UnitEconomicsSort::Revenue,
            UnitEconomicsDirection::Desc,
            null,
        );
    }

    private function cost(
        ContainerInterface $container,
        Company $company,
        string $effectiveFrom,
        int $unitCostMinor,
        ?Uuid $accountId = null,
    ): MarketplaceListingCost {
        return MarketplaceListingCostBuilder::aMarketplaceListingCost()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($accountId ?? Uuid::fromString(self::ACCOUNT_ID))
            ->withMarketplaceSku(self::SKU)
            ->withEffectiveFrom(new \DateTimeImmutable($effectiveFrom))
            ->withUnitCost(Money::ofMinor($unitCostMinor, 'RUB'))
            ->persistWith(new DoctrineMarketplaceListingCostRepository($this->entityManager($container)));
    }

    private function sale(
        ContainerInterface $container,
        Company $company,
        string $day,
        int $quantity,
        int $amountMinor,
        int $commissionMinor,
        string $sourceRowId = 'sale',
    ): void {
        /** @var SalesFactRepository $salesFacts */
        $salesFacts = $container->get(SalesFactRepository::class);

        SalesFactBuilder::aSalesFact()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId(Uuid::fromString(self::ACCOUNT_ID))
            ->withBusinessDate(new \DateTimeImmutable($day))
            ->withMarketplaceSku(self::SKU)
            ->withSourceRowId($sourceRowId)
            ->withStatus('delivered')
            ->withQuantity($quantity)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->withCommissionAmount(Money::ofMinor($commissionMinor, 'RUB'))
            ->persistWith($salesFacts);
    }

    private function company(ContainerInterface $container): Company
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);

        return CompanyBuilder::aCompany()->persistWith($companies);
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
