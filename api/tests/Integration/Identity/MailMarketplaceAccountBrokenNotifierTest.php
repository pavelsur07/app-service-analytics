<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\UserRepository;
use App\Identity\Infrastructure\Notification\MailMarketplaceAccountBrokenNotifier;
use App\Identity\Infrastructure\Query\CompanyMemberEmailsQuery;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * ADR-007: письмо о сломанном подключении зовёт клиента на существующий
 * экран «Подключения», а не в переписку, а его отправка — успешная,
 * отказавшая или без единого получателя — обязана оставить след
 * в журнале уровнем `warning` без адресов получателей и без обрушения
 * вызывающего кода (см. докблок MailMarketplaceAccountBrokenNotifier).
 */
final class MailMarketplaceAccountBrokenNotifierTest extends KernelTestCase
{
    public function testEmailNamesTheConnectionsScreenAndDoesNotClaimThereIsNoSelfServiceOption(): void
    {
        $container = $this->bootedContainer();
        $account = $this->accountWithMember($container, 'owner@example.test');
        $mailer = $this->recordingMailer();

        $this->notifier($container, $mailer)->accountBroken($account->companyId()->toRfc4122(), $account);

        $email = $mailer->messages[0] ?? null;
        self::assertInstanceOf(Email::class, $email);
        $body = (string) $email->getTextBody();
        // Экран существует и работает — письмо обязано на него ссылаться.
        self::assertStringContainsString('Подключения', $body);
        // И не имеет права утверждать обратное устаревшей фразой.
        self::assertStringNotContainsString('Своего экрана', $body);
        self::assertStringNotContainsString('пока нет', $body);
    }

    public function testSuccessfulSendLogsAWarningWithCompanyConnectionShopAndRecipientCount(): void
    {
        $container = $this->bootedContainer();
        $account = $this->accountWithMember($container, 'owner@example.test');
        [$logger, $handler] = $this->logger();

        $this->notifier($container, $this->recordingMailer(), $logger)
            ->accountBroken($account->companyId()->toRfc4122(), $account);

        $record = $this->soleRecord($handler);
        self::assertSame(Level::Warning, $record->level);
        self::assertSame($account->companyId()->toRfc4122(), $record->context['company_id']);
        self::assertSame($account->id()->toRfc4122(), $record->context['marketplace_account_id']);
        self::assertSame($account->externalShopId(), $record->context['external_shop_id']);
        self::assertSame(1, $record->context['recipients_count']);
    }

    public function testRecipientAddressIsAbsentFromTheLogRecord(): void
    {
        $container = $this->bootedContainer();
        // Настоящий, узнаваемый адрес: тест обязан доказать, что именно
        // такое значение не попало ни в сообщение, ни в контекст,
        // а не что там нет какой-то произвольной строки.
        $realEmail = 'director@example.test';
        $account = $this->accountWithMember($container, $realEmail);
        [$logger, $handler] = $this->logger();

        $this->notifier($container, $this->recordingMailer(), $logger)
            ->accountBroken($account->companyId()->toRfc4122(), $account);

        $record = $this->soleRecord($handler);
        self::assertStringNotContainsString($realEmail, $record->message);
        self::assertStringNotContainsString($realEmail, (string) json_encode($record->context));
    }

    public function testSendFailureLogsAWarningAndDoesNotEscape(): void
    {
        $container = $this->bootedContainer();
        $account = $this->accountWithMember($container, 'owner@example.test');
        [$logger, $handler] = $this->logger();
        $failing = $this->failingMailer('SMTP недоступен');

        // Если исключение не перехвачено внутри accountBroken(), этот вызов
        // сам провалит тест — отдельного try/catch здесь не нужно.
        $this->notifier($container, $failing, $logger)
            ->accountBroken($account->companyId()->toRfc4122(), $account);

        $record = $this->soleRecord($handler);
        self::assertSame(Level::Warning, $record->level);
        self::assertSame(1, $record->context['recipients_count']);
        self::assertSame(\RuntimeException::class, $record->context['exception_class']);
        self::assertSame('SMTP недоступен', $record->context['exception_message']);
    }

    /**
     * Ревью: синтетическое сообщение отказа («SMTP недоступен») не способно
     * поймать регрессию, потому что в нём никогда не было адреса.
     * `Symfony\Component\Mailer\Transport\Smtp\SmtpTransport` собирает
     * `UnexpectedResponseException` из сырого ответа сервера, а типовой
     * отказ SMTP эхом возвращает отклонённый адрес прямо в тексте —
     * этот тест воспроизводит именно такую форму, с адресом в угловых
     * скобках, и проверяет, что он не переживает запись.
     */
    public function testSendFailureWithARealisticSmtpMessageDoesNotLeakTheRejectedAddress(): void
    {
        $container = $this->bootedContainer();
        $realEmail = 'owner@example.test';
        $account = $this->accountWithMember($container, $realEmail);
        [$logger, $handler] = $this->logger();
        $failing = $this->failingMailer("550 5.1.1 <{$realEmail}>: Recipient address rejected: User unknown");

        $this->notifier($container, $failing, $logger)
            ->accountBroken($account->companyId()->toRfc4122(), $account);

        $record = $this->soleRecord($handler);
        self::assertSame(Level::Warning, $record->level);
        self::assertStringNotContainsString($realEmail, $record->message);
        self::assertStringNotContainsString($realEmail, (string) json_encode($record->context));
        // Маскировка должна оставить след, а не превратить сообщение
        // в пустую строку — иначе от записи ничего не остаётся.
        self::assertStringContainsString('[redacted]', $this->exceptionMessageOf($record));
    }

    /**
     * Типовой отказ рассылки перечисляет несколько отклонённых адресов
     * в одном ответе сервера — маскировка обязана снять оба, а не только
     * первое совпадение в строке (`preg_replace()` без лимита заменяет
     * все совпадения, но синтетический тест с одним адресом этого
     * не проверяет).
     */
    public function testSendFailureWithSeveralAddressesInOneMessageRedactsAllOfThem(): void
    {
        $container = $this->bootedContainer();
        $account = $this->accountWithMember($container, 'owner@example.test');
        [$logger, $handler] = $this->logger();
        $failing = $this->failingMailer(
            '550 5.1.1 <first@example.test> and <second@example.test>: Recipient address rejected',
        );

        $this->notifier($container, $failing, $logger)
            ->accountBroken($account->companyId()->toRfc4122(), $account);

        $record = $this->soleRecord($handler);
        $exceptionMessage = $this->exceptionMessageOf($record);
        self::assertStringNotContainsString('first@example.test', $exceptionMessage);
        self::assertStringNotContainsString('second@example.test', $exceptionMessage);
        self::assertSame(2, substr_count($exceptionMessage, '[redacted]'));
    }

    public function testAccountWithoutAnyCompanyMemberLogsAWarningInsteadOfThrowing(): void
    {
        $container = $this->bootedContainer();
        $account = $this->accountWithoutMembers($container);
        [$logger, $handler] = $this->logger();
        $mailer = $this->recordingMailer();

        $this->notifier($container, $mailer, $logger)
            ->accountBroken($account->companyId()->toRfc4122(), $account);

        // «Отправить некому» — не отправка: письмо не уходит вовсе.
        self::assertSame([], $mailer->messages);
        $record = $this->soleRecord($handler);
        self::assertSame(Level::Warning, $record->level);
        self::assertSame(0, $record->context['recipients_count']);
    }

    private function failingMailer(string $exceptionMessage): MailerInterface
    {
        return new class($exceptionMessage) implements MailerInterface {
            public function __construct(private string $exceptionMessage)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new \RuntimeException($this->exceptionMessage);
            }
        };
    }

    private function notifier(ContainerInterface $container, MailerInterface $mailer, ?Logger $logger = null): MailMarketplaceAccountBrokenNotifier
    {
        return new MailMarketplaceAccountBrokenNotifier(
            new CompanyMemberEmailsQuery($this->connection($container)),
            $mailer,
            $logger ?? $this->logger()[0],
        );
    }

    private function accountWithMember(ContainerInterface $container, string $email): MarketplaceAccount
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);
        /** @var CompanyMemberRepository $members */
        $members = $container->get(CompanyMemberRepository::class);

        $company = CompanyBuilder::aCompany()->persistWith($companies);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser(UserBuilder::aUser()->withEmail($email)->persistWith($users))
            ->persistWith($companies, $users, $members);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany($company)
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)))
            ->persistWith($companies, $accounts);
    }

    private function accountWithoutMembers(ContainerInterface $container): MarketplaceAccount
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = $container->get(MarketplaceAccountRepository::class);

        return MarketplaceAccountBuilder::aMarketplaceAccount()
            ->withCompany(CompanyBuilder::aCompany()->persistWith($companies))
            ->withExternalShopId('shop-'.bin2hex(random_bytes(4)))
            ->persistWith($companies, $accounts);
    }

    /**
     * @return MailerInterface&object{messages: list<RawMessage>}
     */
    private function recordingMailer(): MailerInterface
    {
        return new class implements MailerInterface {
            /** @var list<RawMessage> */
            public array $messages = [];

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                $this->messages[] = $message;
            }
        };
    }

    private function soleRecord(TestHandler $handler): LogRecord
    {
        $records = $handler->getRecords();
        self::assertCount(1, $records);

        return $records[0];
    }

    private function exceptionMessageOf(LogRecord $record): string
    {
        $message = $record->context['exception_message'] ?? null;
        self::assertIsString($message);

        return $message;
    }

    /**
     * @return array{0: Logger, 1: TestHandler}
     */
    private function logger(): array
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        return [$logger, $handler];
    }

    private function connection(ContainerInterface $container): Connection
    {
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        return $connection;
    }

    private function bootedContainer(): ContainerInterface
    {
        self::bootKernel();

        return self::getContainer();
    }
}
