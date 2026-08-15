<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion;

use App\Identity\Application\Facade\IdentityFacade;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\UserRepository;
use App\Ingestion\Application\CorrectListingCostAction;
use App\Ingestion\Application\SetListingCostAction;
use App\Ingestion\Domain\ListingCostAuditAction;
use App\Ingestion\Domain\ListingCostOutcome;
use App\Ingestion\Domain\MarketplaceListingCostRepository;
use App\Ingestion\Infrastructure\Persistence\DoctrineMarketplaceListingCostRepository;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceListingCostBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Себестоимость: два разных события, аудит и изоляция арендаторов
 * (ADR-013, ADR-011, обязательное покрытие ADR-005).
 *
 * Через сценарии, а не через HTTP: контроллеры §9 тестировать запрещает,
 * а предмет проверки — что новая цена не трогает прошлое, исправление
 * трогает, и оба оставляют след.
 */
final class ListingCostTest extends KernelTestCase
{
    private const string SKU = '220280923';

    public function testNewPriceDoesNotTouchThePast(): void
    {
        $container = $this->bootedContainer();
        $company = $this->companyId($container);
        $account = Uuid::v7()->toRfc4122();
        $actor = $this->actorId($container);

        $july = ($this->set($container))($company, $account, self::SKU, new \DateTimeImmutable('2026-07-01'), Money::ofMinor(42_000, 'RUB'), $actor);
        $august = ($this->set($container))($company, $account, self::SKU, new \DateTimeImmutable('2026-08-01'), Money::ofMinor(51_000, 'RUB'), $actor);

        self::assertSame(ListingCostOutcome::Saved, $july);
        self::assertSame(ListingCostOutcome::Saved, $august);

        // Две строки, а не одна изменённая: товар, проданный в июле,
        // стоил 420 ₽, и августовская поставка не имеет права
        // переписать прибыль за июль.
        $rows = $this->costRows($container, $company);
        self::assertCount(2, $rows);
        self::assertSame([42_000, 51_000], array_map(
            fn (array $row): int => $this->minorAmount($row),
            $rows,
        ));
    }

    public function testSecondPriceForTheSameDateIsRefused(): void
    {
        $container = $this->bootedContainer();
        $company = $this->companyId($container);
        $account = Uuid::v7()->toRfc4122();
        $actor = $this->actorId($container);
        $day = new \DateTimeImmutable('2026-07-01');

        ($this->set($container))($company, $account, self::SKU, $day, Money::ofMinor(42_000, 'RUB'), $actor);
        $second = ($this->set($container))($company, $account, self::SKU, $day, Money::ofMinor(51_000, 'RUB'), $actor);

        // Иначе на один день пришлись бы две себестоимости, и выбор
        // между ними стал бы делом случая. Отказ ловится уникальным
        // индексом, а не проверкой перед вставкой: между проверкой
        // и вставкой два запроса прошли бы её оба.
        self::assertSame(ListingCostOutcome::AlreadySetForThatDate, $second);
        self::assertCount(1, $this->costRows($container, $company));

        // И ни следа в журнале от отказавшей попытки. Запись аудита
        // ставится в единицу работы до вставки, и вопрос «не останется
        // ли она там после отказа» законный: журнал о том, чего
        // не произошло, хуже отсутствующего — ему верят. Ответ даёт
        // Doctrine (UnitOfWork::commit, finally: close + rollBack),
        // но полагаться на чужое поведение без проверки нельзя —
        // поэтому оно проверяется здесь.
        $audit = $this->auditRows($container, $company);
        self::assertCount(1, $audit);
        self::assertSame('42000 RUB с 2026-07-01', $audit[0]['new_value']);
    }

    public function testCorrectionChangesTheValueAndLeavesATrail(): void
    {
        $container = $this->bootedContainer();
        $company = $this->companyId($container);
        $account = Uuid::v7()->toRfc4122();
        $actor = $this->actorId($container);

        $cost = $this->existingCost($container, $company, $account, Money::ofMinor(4_200, 'RUB'));

        // Ввели 42 ₽ вместо 420 — записанное было неправдой, и прибыль
        // за прошедшие дни обязана пересчитаться.
        $outcome = ($this->correct($container))($company, $cost, Money::ofMinor(42_000, 'RUB'), 1, $actor);

        self::assertSame(ListingCostOutcome::Saved, $outcome);
        self::assertSame(42_000, $this->minorAmount($this->costRows($container, $company)[0]));

        // ADR-011: у данных, которые правятся на месте, прежнее значение
        // исчезает, и журнал без «было» отвечает на «кто», но не на «что
        // изменилось». Запись одна: исходную позицию завёл билдер,
        // и в журнал попало ровно проверяемое здесь исправление.
        $audit = $this->auditRows($container, $company);
        self::assertCount(1, $audit);
        self::assertSame(ListingCostAuditAction::Corrected, $audit[0]['action']);
        self::assertSame('4200 RUB с 2026-07-01', $audit[0]['previous_value']);
        self::assertSame('42000 RUB с 2026-07-01', $audit[0]['new_value']);
    }

    public function testCorrectionWithStaleVersionIsRefused(): void
    {
        $container = $this->bootedContainer();
        $company = $this->companyId($container);
        $account = Uuid::v7()->toRfc4122();
        $actor = $this->actorId($container);

        $cost = $this->existingCost($container, $company, $account, Money::ofMinor(4_200, 'RUB'));

        // ADR-008: двое открыли форму одновременно, и второй не должен
        // молча затереть правку первого.
        $outcome = ($this->correct($container))($company, $cost, Money::ofMinor(99_000, 'RUB'), 7, $actor);

        self::assertSame(ListingCostOutcome::VersionConflict, $outcome);
        self::assertSame(4_200, $this->minorAmount($this->costRows($container, $company)[0]));
    }

    public function testCostOfAnotherCompanyIsNotReachable(): void
    {
        $container = $this->bootedContainer();
        $ours = $this->companyId($container);
        $theirs = $this->companyId($container);
        $account = Uuid::v7()->toRfc4122();
        $actor = $this->actorId($container);

        $foreign = $this->existingCost($container, $theirs, $account, Money::ofMinor(42_000, 'RUB'));

        // Обязательное покрытие ADR-005. Себестоимость — коммерческая
        // тайна: зная идентификатор, чужую позицию нельзя ни прочитать,
        // ни исправить.
        $outcome = ($this->correct($container))($ours, $foreign, Money::ofMinor(1, 'RUB'), 1, $actor);

        self::assertSame(ListingCostOutcome::NotFound, $outcome);
        self::assertSame(42_000, $this->minorAmount($this->costRows($container, $theirs)[0]));
    }

    public function testCurrencyCannotBeChangedByCorrection(): void
    {
        $container = $this->bootedContainer();
        $company = $this->companyId($container);
        $account = Uuid::v7()->toRfc4122();
        $actor = $this->actorId($container);

        $cost = $this->existingCost($container, $company, $account, Money::ofMinor(42_000, 'RUB'));

        // Смена валюты у существующей записи означала бы пересчёт
        // по курсу, которого ADR-004 не допускает молча.
        $this->expectException(\InvalidArgumentException::class);

        ($this->correct($container))($company, $cost, Money::ofMinor(42_000, 'CNY'), 1, $actor);
    }

    /**
     * Сценарии собираются здесь, а не берутся из контейнера: HTTP-входа
     * у них пока нет (он в следующем пакете), а приватный сервис без
     * потребителя компилятор контейнера вычищает — та же причина, что
     * в DoctrineMarketplaceRawDocumentRepositoryTest.
     */
    private function set(ContainerInterface $container): SetListingCostAction
    {
        return new SetListingCostAction($this->costs($container), $this->identity($container));
    }

    private function correct(ContainerInterface $container): CorrectListingCostAction
    {
        return new CorrectListingCostAction($this->costs($container), $this->identity($container));
    }

    private function costs(ContainerInterface $container): MarketplaceListingCostRepository
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);

        return new DoctrineMarketplaceListingCostRepository($entityManager);
    }

    private function identity(ContainerInterface $container): IdentityFacade
    {
        /** @var IdentityFacade $facade */
        $facade = $container->get(IdentityFacade::class);

        return $facade;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function costRows(ContainerInterface $container, string $companyId): array
    {
        return $this->connection($container)->fetchAllAssociative(
            'SELECT id, unit_cost_minor, currency, effective_from, version FROM marketplace_listing_cost WHERE company_id = ? ORDER BY effective_from',
            [$companyId],
        );
    }

    /**
     * Исходная позиция для сценариев исправления — билдером (§9),
     * а не вызовом сценария записи: подготовка данных не должна зависеть
     * от того, что проверяет тест, и журнал тогда содержит ровно
     * проверяемое действие.
     */
    private function existingCost(ContainerInterface $container, string $companyId, string $accountId, Money $unitCost): string
    {
        return MarketplaceListingCostBuilder::aMarketplaceListingCost()
            ->withCompanyId(Uuid::fromString($companyId))
            ->withMarketplaceAccountId(Uuid::fromString($accountId))
            ->withMarketplaceSku(self::SKU)
            ->withEffectiveFrom(new \DateTimeImmutable('2026-07-01'))
            ->withUnitCost($unitCost)
            ->persistWith($this->costs($container))
            ->id()
            ->toRfc4122();
    }

    /**
     * BIGINT приезжает из PostgreSQL строкой — приводим явно и с проверкой,
     * а не слепым (int) на mixed.
     *
     * @param array<string, mixed> $row
     */
    private function minorAmount(array $row): int
    {
        $value = $row['unit_cost_minor'];
        self::assertIsNumeric($value);

        return (int) $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(ContainerInterface $container, string $companyId): array
    {
        return $this->connection($container)->fetchAllAssociative(
            // По id, а не по occurred_at: обе записи попадают в одну
            // секунду, и сортировка по времени вырождается в случайную.
            // UUIDv7 упорядочен по времени создания и внутри секунды тоже.
            'SELECT action, previous_value, new_value FROM audit_record WHERE company_id = ? ORDER BY id',
            [$companyId],
        );
    }

    private function companyId(ContainerInterface $container): string
    {
        /** @var CompanyRepository $companies */
        $companies = $container->get(CompanyRepository::class);

        return CompanyBuilder::aCompany()->persistWith($companies)->id()->toRfc4122();
    }

    private function actorId(ContainerInterface $container): string
    {
        /** @var UserRepository $users */
        $users = $container->get(UserRepository::class);

        return UserBuilder::aUser()->persistWith($users)->id()->toRfc4122();
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
