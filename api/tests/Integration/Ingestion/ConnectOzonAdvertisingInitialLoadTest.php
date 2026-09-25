<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\MarketplaceCredentialsEncryptor;
use App\Identity\Domain\UserRepository;
use App\Ingestion\Application\ConnectAdvertisingResult;
use App\Ingestion\Application\ConnectOzonAdvertisingAction;
use App\Ingestion\Domain\OzonAdCampaignListParser;
use App\Ingestion\Domain\OzonAdCampaignProductsParser;
use App\Ingestion\Domain\OzonAdvertisingFetcher;
use App\Ingestion\Infrastructure\Query\Listings\AccountCatalogSkuMatchQuery;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Первичная загрузка рекламы ставится после сохранения ключа (ADR-026
 * п. 4). Отказ очереди в этот момент ключ не отменяет и не превращается
 * в ошибку для клиента, но и не проходит молча — пишется в журнал.
 */
final class ConnectOzonAdvertisingInitialLoadTest extends KernelTestCase
{
    public function testQueueFailureKeepsTheSavedKeyAndIsLogged(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $companies = $container->get(CompanyRepository::class);
        self::assertInstanceOf(CompanyRepository::class, $companies);
        $accounts = $container->get(MarketplaceAccountRepository::class);
        self::assertInstanceOf(MarketplaceAccountRepository::class, $accounts);
        $encryptor = $container->get(MarketplaceCredentialsEncryptor::class);
        self::assertInstanceOf(MarketplaceCredentialsEncryptor::class, $encryptor);
        $users = $container->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $users);
        $members = $container->get(CompanyMemberRepository::class);
        self::assertInstanceOf(CompanyMemberRepository::class, $members);

        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($companies, $users, $members);
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)))
            ->withPlaintextCredentials(['client_id' => 'shop-1', 'api_key' => 'seller-key'], $encryptor)
            ->persistWith($companies, $accounts);

        $log = new TestHandler();
        $identity = $container->get(IdentityFacade::class);
        self::assertInstanceOf(IdentityFacade::class, $identity);
        $catalogMatch = $container->get(AccountCatalogSkuMatchQuery::class);
        self::assertInstanceOf(AccountCatalogSkuMatchQuery::class, $catalogMatch);

        $action = new ConnectOzonAdvertisingAction(
            $this->acceptingFetcher(),
            new OzonAdCampaignListParser(),
            new OzonAdCampaignProductsParser(),
            $catalogMatch,
            $identity,
            new Logger('test', [$log]),
            new class implements MessageBusInterface {
                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    throw new \RuntimeException('Транспорт очереди недоступен.');
                }
            },
        );

        $result = $action(
            $company->id()->toRfc4122(),
            $account->id()->toRfc4122(),
            'perf@advertising.performance.ozon.ru',
            'perf-secret',
            1,
            $user->id()->toRfc4122(),
        );

        self::assertSame(ConnectAdvertisingResult::Connected, $result);
        $connection = $container->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertSame('active', $connection->fetchOne(
            'SELECT advertising_state FROM marketplace_account WHERE company_id = ? AND id = ?',
            [$company->id()->toRfc4122(), $account->id()->toRfc4122()],
        ));
        self::assertTrue($log->hasWarningThatContains('Первичная загрузка рекламы не поставлена в очередь'));
        foreach ($log->getRecords() as $record) {
            self::assertStringNotContainsString('perf-secret', json_encode($record->context, \JSON_THROW_ON_ERROR));
        }
    }

    private function acceptingFetcher(): OzonAdvertisingFetcher
    {
        return new class implements OzonAdvertisingFetcher {
            public function token(string $clientId, string $clientSecret): string
            {
                return 'jwt';
            }

            public function campaigns(string $token): string
            {
                return '{"list":[{"id":"101","state":"CAMPAIGN_STATE_RUNNING","advObjectType":"SKU","createdAt":"2026-09-01T09:08:29Z"}],"total":"1"}';
            }

            public function campaignProducts(string $token, string $campaignId): string
            {
                return '{"products":[]}';
            }

            public function expense(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
            {
                throw new \LogicException('Проба ключа статистику не запрашивает.');
            }

            public function productsSku(string $token, array $campaignIds, \DateTimeImmutable $day): string
            {
                throw new \LogicException('Проба ключа статистику не запрашивает.');
            }

            public function orderSkuReport(string $token, array $campaignIds, \DateTimeImmutable $from, \DateTimeImmutable $to): string
            {
                throw new \LogicException('Проба ключа отчёты не заказывает.');
            }

            public function reportState(string $token, string $uuid): string
            {
                throw new \LogicException('Проба ключа отчёты не заказывает.');
            }

            public function report(string $token, string $uuid): string
            {
                throw new \LogicException('Проба ключа отчёты не заказывает.');
            }

            public function daily(string $token, \DateTimeImmutable $from, \DateTimeImmutable $to): string
            {
                throw new \LogicException('Проба ключа статистику не запрашивает.');
            }
        };
    }
}
