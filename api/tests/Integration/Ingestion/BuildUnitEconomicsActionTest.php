<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Ingestion\Application\BuildUnitEconomicsAction;
use App\Ingestion\Application\UnitEconomicsReport;
use App\Ingestion\Domain\MarketplaceExpenseFactRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Ingestion\Domain\SalesFactRepository;
use App\Ingestion\Infrastructure\Query\UnitEconomicsCursor;
use App\Ingestion\Infrastructure\Query\UnitEconomicsDirection;
use App\Ingestion\Infrastructure\Query\UnitEconomicsSort;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceExpenseFactBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Расчёт юнит-экономики: денежная арифметика и сборка отчёта.
 * ADR-005 относит денежную арифметику к обязательному покрытию,
 * а тестировать контроллеры §9 запрещает — поэтому здесь, а не
 * через HTTP.
 */
final class BuildUnitEconomicsActionTest extends KernelTestCase
{
    private const string DAY = '2026-07-01';

    private const string ACCOUNT_ID = '019fe6ea-cd99-7af8-bf4a-623a5a31cf7b';

    public function testMarginIsRevenuePlusNegativeCommissionAndExpenses(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '111', 274_700, -126_362);
        $this->expense($container, $company, '111', 32, -6_900);
        $this->expense($container, $company, '111', 29, -785);

        $report = $this->build($container, $company);

        self::assertCount(1, $report->skus);
        $sku = $report->skus[0];
        self::assertSame(274_700, $sku->revenueMinor);
        self::assertSame(-126_362, $sku->commissionMinor);
        self::assertSame(-7_685, $sku->expensesTotalMinor);
        // Комиссия и расходы приходят от площадки отрицательными,
        // поэтому итог — сложение. «Взять по модулю» означало бы
        // сложить расход с выручкой и показать прибыль вдвое больше.
        self::assertSame(-134_047, $sku->deductionsTotalMinor);
        self::assertSame(274_700 - 126_362 - 7_685, $sku->marginMinor);
    }

    public function testExpensesAreGroupedByTypeWithTheLargestFirst(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '111', 100_000, -1_000);
        $this->expense($container, $company, '111', 29, -785);
        $this->expense($container, $company, '111', 32, -6_900, accrualId: 2);
        $this->expense($container, $company, '111', 32, -100, accrualId: 3);

        $report = $this->build($container, $company);

        $expenses = $report->skus[0]->expenses;
        self::assertCount(2, $expenses);
        // Одинаковые типы сложены, и первым идёт тот, что съедает
        // больше всего, — ради него экран и открывают.
        self::assertSame(32, $expenses[0]->feeTypeId);
        self::assertSame(-7_000, $expenses[0]->amountMinor);
        self::assertSame('Логистика', $expenses[0]->name);
        self::assertSame(-785, $expenses[1]->amountMinor);
    }

    public function testCabinetExpensesAreApartFromProducts(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->expense($container, $company, '', 41, -23_793);

        $report = $this->build($container, $company);

        // Реклама и хранение не размазываются по товарам (ADR-012):
        // базис распределения захочется менять, а показанная строка
        // честнее доли, происхождение которой клиент не проверит.
        self::assertSame([], $report->skus);
        self::assertSame(-23_793, $report->cabinetExpensesTotalMinor);
        self::assertSame('Оплата за клик', $report->cabinetExpenses[0]->name);
    }

    public function testProductWithExpensesButNoSalesIsIncluded(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Возврат обработали в этом периоде, а продан товар был раньше.
        // Спрятать такой расход значило бы занизить издержки.
        $this->expense($container, $company, '222', 59, -11_500);

        $report = $this->build($container, $company);

        self::assertCount(1, $report->skus);
        self::assertSame('222', $report->skus[0]->marketplaceSku);
        self::assertSame(0, $report->skus[0]->revenueMinor);
        self::assertSame(-11_500, $report->skus[0]->marginMinor);
    }

    public function testPageIsLimitedAndCursorLeadsToTheRest(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '111', 300_000, -1_000, 'sale-1');
        $this->sale($container, $company, '222', 200_000, -1_000, 'sale-2');
        $this->sale($container, $company, '333', 100_000, -1_000, 'sale-3');

        $first = $this->build($container, $company, limit: 2);

        // Товар с расходами, но без продаж, объединяется в SQL, а не
        // дописывается в PHP: иначе он проходил бы мимо лимита страницы,
        // и отчёт превышал бы собственный потолок.
        self::assertCount(2, $first->skus);
        self::assertSame(['111', '222'], array_map(
            static fn ($sku): string => $sku->marketplaceSku,
            $first->skus,
        ));
        self::assertNotNull($first->nextCursor);

        $second = $this->build($container, $company, limit: 2, cursor: UnitEconomicsCursor::fromString($first->nextCursor));

        self::assertSame(['333'], array_map(
            static fn ($sku): string => $sku->marketplaceSku,
            $second->skus,
        ));
        self::assertNull($second->nextCursor);
    }

    /**
     * Сортировка по марже: она считается запросом ради ORDER BY,
     * а в ответ приходит с Money. Тест держит два источника одной
     * цифры вместе — разъедься они, страница упорядочилась бы
     * по величине, которой на экране нет.
     */
    public function testPageIsOrderedByTheRequestedMetric(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        // Маржа = выручка + комиссия + расходы, все знаковые.
        $this->sale($container, $company, '111', 300_000, -10_000, 'sale-1');
        $this->expense($container, $company, '111', 32, -250_000);
        $this->sale($container, $company, '222', 200_000, -10_000, 'sale-2');
        $this->sale($container, $company, '333', 100_000, -10_000, 'sale-3');

        $report = $this->build($container, $company, sort: UnitEconomicsSort::Margin, direction: UnitEconomicsDirection::Asc);

        self::assertSame(['111', '333', '222'], array_map(
            static fn ($sku): string => $sku->marketplaceSku,
            $report->skus,
        ));

        $margins = array_map(static fn ($sku): int => $sku->marginMinor, $report->skus);
        $sorted = $margins;
        sort($sorted);
        self::assertSame($sorted, $margins, 'Порядок обязан быть монотонным по marginMinor из ответа.');
    }

    /**
     * Равные значения сортируемой колонки — не край, а норма: у товаров
     * без продаж доставленных штук ноль у всех. Устойчивость держит
     * тай-брейк по артикулу; без него строки на границе страницы
     * пропадают и повторяются.
     */
    public function testTiedSortValuesPaginateWithoutGapsOrRepeats(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        foreach (['111', '222', '333'] as $sku) {
            $this->expense($container, $company, $sku, 32, -10_000);
        }

        $seen = [];
        $cursor = null;

        do {
            $page = $this->build(
                $container,
                $company,
                limit: 2,
                cursor: $cursor,
                sort: UnitEconomicsSort::Delivered,
                direction: UnitEconomicsDirection::Desc,
            );

            foreach ($page->skus as $sku) {
                $seen[] = $sku->marketplaceSku;
            }

            $cursor = null === $page->nextCursor
                ? null
                : UnitEconomicsCursor::fromString($page->nextCursor);
        } while (null !== $cursor);

        self::assertSame(['111', '222', '333'], $seen);
    }

    /**
     * Название и артикул селлера берутся из каталога, но строка расчёта
     * от него не зависит: артикул площадки встречается в фактах раньше,
     * чем карточка, и терять из-за этого продажи нельзя.
     */
    public function testCatalogFillsNameAndOfferIdWithoutDroppingRows(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $accountId = Uuid::v7();

        $this->sale($container, $company, '111', 300_000, -1_000, 'sale-1');
        $this->sale($container, $company, '222', 200_000, -1_000, 'sale-2');
        $this->listings($container, $company, $accountId, ['111']);

        $report = $this->build($container, $company);

        self::assertSame('Товар 111', $report->skus[0]->name);
        self::assertSame('offer-111', $report->skus[0]->offerId);
        // Карточки нет — но строка есть, и это главное.
        self::assertSame('222', $report->skus[1]->marketplaceSku);
        self::assertNull($report->skus[1]->name);
        self::assertNull($report->skus[1]->offerId);
    }

    /**
     * Ключ каталога — (компания, подключение, артикул), а агрегат
     * схлопнут по артикулу. Один и тот же артикул под двумя
     * подключениями обязан дать одну строку: иначе страница задвоится
     * и под лимитом придёт меньше товаров, чем обещано.
     */
    public function testSameSkuInTwoConnectionsStaysOneRow(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '111', 300_000, -1_000, 'sale-1');
        $this->listings($container, $company, Uuid::v7(), ['111']);
        $this->listings($container, $company, Uuid::v7(), ['111']);

        $report = $this->build($container, $company);

        self::assertCount(1, $report->skus);
        self::assertSame('111', $report->skus[0]->marketplaceSku);
    }

    /**
     * Курсор от другой сортировки не должен молча дать страницу:
     * выборка отсеклась бы по одному показателю, а порядок шёл бы
     * по другому. HTTP-граница это проверяет, но сценарий публичный.
     */
    public function testCursorFromAnotherSortOrderIsRefused(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->sale($container, $company, '111', 300_000, -1_000, 'sale-1');

        $this->expectException(\InvalidArgumentException::class);

        $this->build(
            $container,
            $company,
            cursor: new UnitEconomicsCursor(
                UnitEconomicsSort::Revenue,
                UnitEconomicsDirection::Desc,
                100,
                '111',
            ),
            sort: UnitEconomicsSort::Margin,
        );
    }

    public function testDataOfAnotherCompanyIsNotCounted(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $foreign = $this->company($container);

        $this->sale($container, $company, '111', 100_000, -1_000);
        $this->sale($container, $foreign, '111', 999_999, -99_999, 'foreign-1');
        $this->expense($container, $foreign, '111', 32, -99_999);

        $report = $this->build($container, $company);

        // Обязательное покрытие ADR-005: артикул один и тот же,
        // разделяет только company_id в самом запросе.
        self::assertCount(1, $report->skus);
        self::assertSame(100_000, $report->skus[0]->revenueMinor);
        self::assertSame(0, $report->skus[0]->expensesTotalMinor);
    }

    public function testDayWithSalesButWithoutLoadedExpensesIsCounted(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->upload($container, $company, MarketplaceReportType::OzonPostingFboList);

        $report = $this->build($container, $company);

        // За такой день экран показывает выручку минус одну комиссию
        // и называет это маржой. По самим данным он неотличим от дня,
        // когда начислений правда не было, — отличает только то, была ли
        // выгрузка (CLAUDE.md, «Наблюдаемость»).
        self::assertSame(1, $report->daysWithoutExpenses);
    }

    public function testDayWithBothUploadsIsNotCounted(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->upload($container, $company, MarketplaceReportType::OzonPostingFboList);
        $this->upload($container, $company, MarketplaceReportType::OzonAccrualByDay);

        $report = $this->build($container, $company);

        // Расходы выгружены — то, что начислений в них ноль, не дыра:
        // у продавца бывает день без единого заказа.
        self::assertSame(0, $report->daysWithoutExpenses);
    }

    public function testDaysBeforeTheFirstSalesUploadAreNotCounted(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);

        $this->upload($container, $company, MarketplaceReportType::OzonPostingFboList);
        $this->upload($container, $company, MarketplaceReportType::OzonAccrualByDay);

        $report = $this->build(
            $container,
            $company,
            from: (new \DateTimeImmutable(self::DAY))->modify('-89 day'),
        );

        // Окно в 90 дней у клиента, подключившегося вчера, не объявляет
        // несчитанными восемьдесят девять дней, которых у него никогда
        // не было. Тревога, которая горит всегда, перестаёт читаться.
        self::assertSame(0, $report->daysWithoutExpenses);
    }

    public function testUploadOfOneAccountDoesNotCoverAnother(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $covered = Uuid::v7();
        $uncovered = Uuid::v7();

        $this->upload($container, $company, MarketplaceReportType::OzonPostingFboList, $covered);
        $this->upload($container, $company, MarketplaceReportType::OzonAccrualByDay, $covered);
        $this->upload($container, $company, MarketplaceReportType::OzonPostingFboList, $uncovered);

        $report = $this->build($container, $company);

        // Загруженный день одного кабинета не означает загруженный день
        // другого: расходы второго в отчёт не попали, и день дырявый.
        self::assertSame(1, $report->daysWithoutExpenses);
    }

    public function testUploadOfAnotherCompanyDoesNotCloseTheGap(): void
    {
        $container = $this->bootedContainer();
        $company = $this->company($container);
        $foreign = $this->company($container);

        $this->upload($container, $company, MarketplaceReportType::OzonPostingFboList);
        $this->upload($container, $foreign, MarketplaceReportType::OzonAccrualByDay);

        $report = $this->build($container, $company);

        // Обязательное покрытие ADR-005: без company_id в запросе чужая
        // исправная выгрузка закрывала бы нашу дыру, и предупреждение
        // не показалось бы именно тогда, когда должно.
        self::assertSame(1, $report->daysWithoutExpenses);
    }

    private function build(
        ContainerInterface $container,
        Company $company,
        int $limit = 50,
        ?UnitEconomicsCursor $cursor = null,
        ?\DateTimeImmutable $from = null,
        UnitEconomicsSort $sort = UnitEconomicsSort::Revenue,
        UnitEconomicsDirection $direction = UnitEconomicsDirection::Desc,
    ): UnitEconomicsReport {
        /** @var BuildUnitEconomicsAction $action */
        $action = $container->get(BuildUnitEconomicsAction::class);

        return ($action)(
            $company->id()->toRfc4122(),
            $from ?? new \DateTimeImmutable(self::DAY),
            new \DateTimeImmutable(self::DAY),
            $limit,
            $sort,
            $direction,
            $cursor,
        );
    }

    /**
     * Загрузка, а не факты: покрытие меряется появлением raw-документа,
     * потому что пустой ответ площадки — тоже ответ.
     */
    private function upload(
        ContainerInterface $container,
        Company $company,
        string $reportType,
        ?Uuid $marketplaceAccountId = null,
    ): void {
        /** @var MarketplaceRawDocumentRepository $rawDocuments */
        $rawDocuments = $container->get(MarketplaceRawDocumentRepository::class);

        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($company->id())
            // Одно подключение по умолчанию: продажи и расходы одного дня
            // должны прийти из одного кабинета, иначе тест проверял бы
            // не покрытие, а разные кабинеты.
            ->withMarketplaceAccountId($marketplaceAccountId ?? Uuid::fromString(self::ACCOUNT_ID))
            ->withReportType($reportType)
            ->withPeriod(new \DateTimeImmutable(self::DAY))
            ->persistWith($rawDocuments);
    }

    private function sale(
        ContainerInterface $container,
        Company $company,
        string $sku,
        int $amountMinor,
        int $commissionMinor,
        string $sourceRowId = 'sale',
    ): void {
        /** @var SalesFactRepository $salesFacts */
        $salesFacts = $container->get(SalesFactRepository::class);

        SalesFactBuilder::aSalesFact()
            ->withCompanyId($company->id())
            ->withBusinessDate(new \DateTimeImmutable(self::DAY))
            ->withMarketplaceSku($sku)
            ->withSourceRowId($sourceRowId.'-'.$sku)
            ->withStatus('delivered')
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->withCommissionAmount(Money::ofMinor($commissionMinor, 'RUB'))
            ->persistWith($salesFacts);
    }

    private function expense(
        ContainerInterface $container,
        Company $company,
        string $sku,
        int $feeTypeId,
        int $amountMinor,
        int $accrualId = 1,
    ): void {
        /** @var MarketplaceExpenseFactRepository $expenseFacts */
        $expenseFacts = $container->get(MarketplaceExpenseFactRepository::class);

        MarketplaceExpenseFactBuilder::aMarketplaceExpenseFact()
            ->withCompanyId($company->id())
            ->withBusinessDate(new \DateTimeImmutable(self::DAY))
            ->withMarketplaceSku($sku)
            ->withFeeTypeId($feeTypeId)
            ->withAccrualId($accrualId)
            ->withAmount(Money::ofMinor($amountMinor, 'RUB'))
            ->persistWith($expenseFacts);
    }

    /**
     * @param list<string> $skus
     */
    private function listings(
        ContainerInterface $container,
        Company $company,
        Uuid $accountId,
        array $skus,
    ): void {
        /** @var MarketplaceListingRepository $listings */
        $listings = $container->get(MarketplaceListingRepository::class);

        $listings->replaceForAccount(
            $company->id()->toRfc4122(),
            $accountId,
            array_map(
                static fn (string $sku) => MarketplaceListingBuilder::aMarketplaceListing()
                    ->withCompanyId($company->id())
                    ->withMarketplaceAccountId($accountId)
                    ->withMarketplaceSku($sku)
                    ->withOfferId('offer-'.$sku)
                    ->withName('Товар '.$sku)
                    ->build(),
                $skus,
            ),
        );
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
