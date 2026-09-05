<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ingestion;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceRawDocumentRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Удаление подключения, которое ничего не загрузило.
 *
 * Через HTTP проверяется обязательное покрытие §9: изоляция арендаторов
 * живёт в подписчике kernel.controller, а повтор удаления и есть предмет
 * проверки идемпотентности.
 */
final class DiscardConnectionControllerTest extends WebTestCase
{
    public function testConnectionWithoutHistoryIsDeletedAndAuditRecorded(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company);

        $this->delete($client, $company, $account);

        self::assertSame(204, $client->getResponse()->getStatusCode());
        self::assertNull($this->accountRow($account));

        // «Добавление и изменение учётных данных подключений» ADR-007
        // распространяется и на удаление (CLAUDE.md, «Обязательные
        // правила», задача): строка исчезла целиком, и без записи
        // не у кого было бы спросить, что она существовала.
        $record = $this->connectionOf()->fetchAssociative(
            'SELECT action, company_id, subject_id, previous_value, new_value FROM audit_record WHERE subject_id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertIsArray($record);
        self::assertSame('marketplace_account.discarded', $record['action']);
        self::assertSame($company->id()->toRfc4122(), $record['company_id']);
        self::assertSame($account->id()->toRfc4122(), $record['subject_id']);
        self::assertNull($record['new_value']);
        self::assertIsString($record['previous_value']);
        // Название и кабинет — не ключ: секрет в журнал не попадает
        // ни в каком виде.
        self::assertStringContainsString('shop-1', $record['previous_value']);
        self::assertStringNotContainsString('stub-ciphertext', $record['previous_value']);
    }

    public function testConnectionWithHistoryIsNotDeleted(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company);
        MarketplaceRawDocumentBuilder::aMarketplaceRawDocument()
            ->withCompanyId($company->id())
            ->withMarketplaceAccountId($account->id())
            ->persistWith($this->rawDocuments());

        $this->delete($client, $company, $account);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame('connection_has_history', $this->responseCode($client));
        self::assertNotNull($this->accountRow($account));
        self::assertSame(0, $this->auditCount($account));
    }

    public function testRepeatedDeleteOfAlreadyDeletedConnectionIsNotFound(): void
    {
        $client = static::createClient();
        // Второй запрос в одном тесте: без него KernelBrowser
        // перезапускает ядро между ними и оба входа считались бы разными
        // «первыми» запросами.
        $client->disableReboot();
        $company = $this->loginAsCompanyMember($client);
        $account = $this->connection($company);

        $this->delete($client, $company, $account);
        self::assertSame(204, $client->getResponse()->getStatusCode());

        $this->delete($client, $company, $account);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame('connection_not_found', $this->responseCode($client));
        self::assertSame(1, $this->auditCount($account));
    }

    public function testForeignCompanyIsRejectedAndConnectionStaysInPlace(): void
    {
        $client = static::createClient();
        // Обязательное покрытие §9: изоляция данных между компаниями.
        // Пользователь состоит в своей компании, но не в чужой — попытка
        // удалить подключение чужой компании обязана получить 403
        // от CompanyAccessSubscriber, до контроллера запрос не доходит.
        $this->loginAsCompanyMember($client);
        $foreign = CompanyBuilder::aCompany()->persistWith($this->companies());
        $foreignAccount = $this->connection($foreign);

        $this->delete($client, $foreign, $foreignAccount);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->accountRow($foreignAccount));
        self::assertSame(0, $this->auditCount($foreignAccount));
    }

    public function testConnectionOfAnotherCompanyUnderOwnPathIsNotFound(): void
    {
        $client = static::createClient();
        $company = $this->loginAsCompanyMember($client);
        // companyId в пути — свой, но подключение принадлежит другой
        // компании: защитой служит только companyId в самом чтении
        // (DoctrineMarketplaceAccountRepository::deleteIfNoHistory),
        // не только подписчик членства.
        $foreign = $this->connection(CompanyBuilder::aCompany()->persistWith($this->companies()));

        $this->delete($client, $company, $foreign);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertNotNull($this->accountRow($foreign));
    }

    private function connection(Company $company): MarketplaceAccount
    {
        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-1')
            ->persistWith($this->companies(), $this->marketplaceAccounts());
    }

    private function delete(KernelBrowser $client, Company $company, MarketplaceAccount $account): void
    {
        $client->request(
            'DELETE',
            "/api/companies/{$company->id()->toRfc4122()}/connections/{$account->id()->toRfc4122()}",
        );
    }

    private function responseCode(KernelBrowser $client): string
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        /** @var array{code: string} $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload['code'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accountRow(MarketplaceAccount $account): ?array
    {
        $row = $this->connectionOf()->fetchAssociative(
            'SELECT * FROM marketplace_account WHERE id = ?',
            [$account->id()->toRfc4122()],
        );

        return false === $row ? null : $row;
    }

    private function auditCount(MarketplaceAccount $account): int
    {
        $count = $this->connectionOf()->fetchOne(
            'SELECT COUNT(*) FROM audit_record WHERE subject_id = ?',
            [$account->id()->toRfc4122()],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function loginAsCompanyMember(KernelBrowser $client): Company
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users = new DoctrineUserRepository($entityManager);
        $companyMembers = new DoctrineCompanyMemberRepository($entityManager);

        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($this->companies(), $users, $companyMembers);

        $client->loginUser($user, 'api');

        return $company;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return $companies;
    }

    private function marketplaceAccounts(): MarketplaceAccountRepository
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);

        return $accounts;
    }

    private function rawDocuments(): MarketplaceRawDocumentRepository
    {
        /** @var MarketplaceRawDocumentRepository $rawDocuments */
        $rawDocuments = static::getContainer()->get(MarketplaceRawDocumentRepository::class);

        return $rawDocuments;
    }

    private function connectionOf(): Connection
    {
        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);

        return $connection;
    }
}
