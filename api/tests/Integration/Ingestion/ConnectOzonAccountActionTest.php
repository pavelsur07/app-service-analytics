<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Application\ConnectOzonAccountAction;
use App\Ingestion\Application\ConnectOzonAccountResult;
use App\Ingestion\Application\Message\FetchOzonCatalogMessage;
use App\Ingestion\Application\Message\FetchOzonExpensesMessage;
use App\Ingestion\Application\Message\FetchOzonPostingsMessage;
use App\Ingestion\Domain\OzonCatalogFetcher;
use App\Ingestion\Infrastructure\Connector\Ozon\OzonProductListClient;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Подключение кабинета при онбординге (ADR-021): ключи проверяются живым
 * запросом ДО сохранения, и три исхода различаются, потому что клиенту
 * нужно разное действие в каждом.
 *
 * Обращений к настоящему Ozon нет (ADR-005).
 */
final class ConnectOzonAccountActionTest extends KernelTestCase
{
    public function testAcceptedKeyIsSavedAndSchedulesTheCurrentMonth(): void
    {
        [$companyId, $userId] = $this->companyWithOwner();
        $this->ozonAnswers(200);

        $outcome = ($this->action())($companyId, 'Мой магазин', 'shop-1', 'live-key', $userId);

        self::assertSame(ConnectOzonAccountResult::Connected, $outcome->result);
        self::assertNotNull($outcome->accountId);

        // Не «данные появятся ночью»: сообщение в очередь сразу после
        // сохранения (ADR-021).
        $dispatched = $this->dispatchedMessages();
        self::assertContains(FetchOzonCatalogMessage::class, $dispatched);
        self::assertContains(FetchOzonPostingsMessage::class, $dispatched);
        self::assertContains(FetchOzonExpensesMessage::class, $dispatched);
    }

    public function testRejectedKeyIsNotSaved(): void
    {
        [$companyId, $userId] = $this->companyWithOwner();
        // Подключение, созданное с неверными ключами, — это broken через
        // несколько часов и клиент, который считает, что всё настроил.
        $this->ozonAnswers(401);

        $outcome = ($this->action())($companyId, 'Мой магазин', 'shop-1', 'wrong-key', $userId);

        self::assertSame(ConnectOzonAccountResult::Rejected, $outcome->result);
        self::assertSame(0, $this->accountCount($companyId));
        self::assertSame([], $this->dispatchedMessages());
    }

    public function testUnavailableMarketplaceIsNotReportedAsAWrongKey(): void
    {
        [$companyId, $userId] = $this->companyWithOwner();
        // Лимит запросов и сбой площадки лечатся повтором, а не выпуском
        // нового ключа. Сказать «ключ не подошёл» здесь значит отправить
        // клиента делать бесполезную работу.
        $this->ozonAnswers(503);

        $outcome = ($this->action())($companyId, 'Мой магазин', 'shop-1', 'live-key', $userId);

        self::assertSame(ConnectOzonAccountResult::Unavailable, $outcome->result);
        self::assertSame(0, $this->accountCount($companyId));
    }

    public function testCabinetOfAnotherCompanyIsReportedAsAlreadyConnected(): void
    {
        $companies = $this->companies();
        MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-taken')
            ->persistWith($companies, $this->marketplaceAccounts());

        [$companyId, $userId] = $this->companyWithOwner();
        $this->ozonAnswers(200);

        $outcome = ($this->action())($companyId, 'Второй магазин', 'shop-taken', 'live-key', $userId);

        self::assertSame(ConnectOzonAccountResult::AlreadyConnected, $outcome->result);
        self::assertSame(0, $this->accountCount($companyId));
    }

    /** @return list<string> */
    private function dispatchedMessages(): array
    {
        $transport = static::getContainer()->get('messenger.transport.async_ingestion');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return array_values(array_map(
            static fn (object $envelope): string => $envelope->getMessage()::class,
            $transport->getSent(),
        ));
    }

    private function accountCount(string $companyId): int
    {
        $connection = static::getContainer()->get(\Doctrine\DBAL\Connection::class);
        self::assertInstanceOf(\Doctrine\DBAL\Connection::class, $connection);

        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM marketplace_account WHERE company_id = ?',
            [$companyId],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function ozonAnswers(int $status): void
    {
        $body = 200 === $status
            ? '{"result":{"items":[],"total":0,"last_id":""}}'
            : '{"code":16,"message":"unauthenticated"}';

        static::getContainer()->set(OzonProductListClient::class, new class($body, $status) implements OzonCatalogFetcher {
            public function __construct(
                private readonly string $body,
                private readonly int $status,
            ) {
            }

            public function fetchPage(string $clientId, string $apiKey, string $lastId, int $limit = 1000): string
            {
                $client = new MockHttpClient(new MockResponse($this->body, ['http_code' => $this->status]));

                return $client->request('POST', 'https://api-seller.ozon.ru/v3/product/list')->getContent();
            }
        });
    }

    private function action(): ConnectOzonAccountAction
    {
        $action = static::getContainer()->get(ConnectOzonAccountAction::class);
        self::assertInstanceOf(ConnectOzonAccountAction::class, $action);

        return $action;
    }

    private function companies(): CompanyRepository
    {
        $companies = static::getContainer()->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);

        return $companies;
    }

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);

        return $accounts;
    }

    /** @return array{string, string} */
    private function companyWithOwner(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        $users = new DoctrineUserRepository($entityManager);
        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($this->companies(), $users, new DoctrineCompanyMemberRepository($entityManager));

        return [$company->id()->toRfc4122(), $user->id()->toRfc4122()];
    }
}
