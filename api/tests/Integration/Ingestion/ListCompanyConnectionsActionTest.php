<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\ValueObject\MarketplaceAccountState;
use App\Ingestion\Application\ListCompanyConnectionsAction;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Ingestion\Domain\MarketplaceReportType;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Сборка экрана подключений: состояние из Identity плюс свежесть
 * из raw-слоя. Проверяется здесь, а не через HTTP: контроллер только
 * перекладывает поля, а тестировать контроллеры CLAUDE.md §9 запрещает.
 * Через HTTP остаётся то, что живёт именно там, — изоляция арендаторов
 * и отсутствие учётных данных в теле ответа.
 */
final class ListCompanyConnectionsActionTest extends KernelTestCase
{
    public function testFreshnessIsReportedPerReportType(): void
    {
        $container = $this->bootedContainer();
        $company = CompanyBuilder::aCompany()->persistWith($this->companies($container));
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->persistWith($this->companies($container), $this->accounts($container));

        // Две выгрузки одного подключения с разными датами: экран обязан
        // показать их порознь, иначе исправный каталог маскировал бы
        // вставшие продажи — ровно то, от чего защищён и сторож свежести.
        $this->rawDocument($container, $company->id()->toRfc4122(), $account->id()->toRfc4122(), MarketplaceReportType::OzonPostingFboList, '2026-08-10 09:00:00');
        $this->rawDocument($container, $company->id()->toRfc4122(), $account->id()->toRfc4122(), MarketplaceReportType::OzonProductList, '2026-08-14 09:00:00');

        $connections = ($this->action($container))($company->id()->toRfc4122());

        self::assertCount(1, $connections);
        self::assertSame('active', $connections[0]->state);
        // assertEquals, а не assertSame: у ассоциативного массива
        // последний сравнивает и порядок ключей, а порядок здесь ничей
        // не контракт — свежесть по типам это отображение, и запрос
        // не сортирует. С assertSame тест краснел раз через раз,
        // в зависимости от того, в каком порядке PostgreSQL вернул строки.
        self::assertEquals(
            [
                MarketplaceReportType::OzonPostingFboList => '2026-08-10T09:00:00+00:00',
                MarketplaceReportType::OzonProductList => '2026-08-14T09:00:00+00:00',
            ],
            $connections[0]->lastLoadedAt,
        );
    }

    public function testTimestampsCarryTheirTimeZone(): void
    {
        $container = $this->bootedContainer();
        $company = CompanyBuilder::aCompany()->persistWith($this->companies($container));
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->persistWith($this->companies($container), $this->accounts($container));

        $connections = ($this->action($container))($company->id()->toRfc4122());

        // Колонка timestamp without time zone, и без явного пояса браузер
        // разбирает «2026-08-14 09:00:00» как местное время: цифра
        // сдвигается на величину пояса пользователя, а <time dateTime>
        // получает значение, недопустимое в HTML.
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $connections[0]->createdAt,
        );
    }

    public function testBrokenConnectionWithoutLoadsIsDistinguishable(): void
    {
        $container = $this->bootedContainer();
        $company = CompanyBuilder::aCompany()->persistWith($this->companies($container));
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withState(MarketplaceAccountState::Broken)
            ->persistWith($this->companies($container), $this->accounts($container));

        $connections = ($this->action($container))($company->id()->toRfc4122());

        // «Сломано» и «загрузок не было» — разные факты: подключение может
        // быть сломано после месяца работы, а может не проработать ни дня.
        self::assertSame('broken', $connections[0]->state);
        self::assertSame([], $connections[0]->lastLoadedAt);
    }

    private function action(ContainerInterface $container): ListCompanyConnectionsAction
    {
        /** @var ListCompanyConnectionsAction $action */
        $action = $container->get(ListCompanyConnectionsAction::class);

        return $action;
    }

    private function rawDocument(ContainerInterface $container, string $companyId, string $accountId, string $reportType, string $receivedAt): void
    {
        /** @var MarketplaceRawDocumentRepository $rawDocuments */
        $rawDocuments = $container->get(MarketplaceRawDocumentRepository::class);

        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId(\Symfony\Component\Uid\Uuid::fromString($companyId))
            ->withMarketplaceAccountId(\Symfony\Component\Uid\Uuid::fromString($accountId))
            ->withReportType($reportType)
            ->withReceivedAt(new \DateTimeImmutable($receivedAt))
            ->persistWith($rawDocuments);
    }

    private function companies(ContainerInterface $container): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);

        return $companies;
    }

    private function accounts(ContainerInterface $container): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);

        return $accounts;
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
