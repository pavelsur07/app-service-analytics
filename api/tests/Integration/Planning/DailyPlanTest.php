<?php

declare(strict_types=1);

namespace App\Tests\Integration\Planning;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\UserRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Planning\Application\ReadDailyPlanAction;
use App\Planning\Application\RemoveDailyPlanAction;
use App\Planning\Application\SaveDailyPlanAction;
use App\Planning\Domain\DailyPlanMutationOutcome;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DailyPlanTest extends KernelTestCase
{
    public function testSameSkuInDifferentAccountsDoesNotIntersect(): void
    {
        self::bootKernel();
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = self::getContainer()->get(MarketplaceAccountRepository::class);
        /** @var MarketplaceListingRepository $listings */
        $listings = self::getContainer()->get(MarketplaceListingRepository::class);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $firstAccount = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)
            ->withExternalShopId('first-shop')->persistWith($companies, $accounts);
        $secondAccount = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)
            ->withExternalShopId('second-shop')->persistWith($companies, $accounts);
        foreach ([$firstAccount, $secondAccount] as $account) {
            $listings->replaceForAccount($company->id()->toRfc4122(), $account->id(), [
                MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($company->id())
                    ->withMarketplaceAccountId($account->id())->withMarketplaceSku('ОБЩИЙ-SKU')->build(),
            ]);
        }
        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        $actor = UserBuilder::aUser()->persistWith($users);
        /** @var SaveDailyPlanAction $save */
        $save = self::getContainer()->get(SaveDailyPlanAction::class);
        /** @var ReadDailyPlanAction $read */
        $read = self::getContainer()->get(ReadDailyPlanAction::class);
        $companyId = $company->id()->toRfc4122();
        $actorId = $actor->id()->toRfc4122();
        $day = new \DateTimeImmutable('2026-09-21');

        $save($companyId, $firstAccount->id()->toRfc4122(), 'ОБЩИЙ-SKU', $day, 11, 0, $actorId);
        $save($companyId, $secondAccount->id()->toRfc4122(), 'ОБЩИЙ-SKU', $day, 22, 0, $actorId);

        $first = $read($companyId, $firstAccount->id()->toRfc4122(), 'ОБЩИЙ-SKU', $day, $day);
        $second = $read($companyId, $secondAccount->id()->toRfc4122(), 'ОБЩИЙ-SKU', $day, $day);
        self::assertSame(11, $first->items[0]->quantity);
        self::assertSame(22, $second->items[0]->quantity);

        $otherCompany = CompanyBuilder::aCompany()->withName('Other')->persistWith($companies);
        $foreign = $read($otherCompany->id()->toRfc4122(), $firstAccount->id()->toRfc4122(), 'ОБЩИЙ-SKU', $day, $day);
        self::assertSame(DailyPlanMutationOutcome::AccountNotFound, $foreign->outcome);
        self::assertSame([], $foreign->items);
    }

    public function testCreateChangeRemoveAndReadKeepVersionsAndAudit(): void
    {
        self::bootKernel();
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = self::getContainer()->get(MarketplaceAccountRepository::class);
        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        /** @var MarketplaceListingRepository $listings */
        $listings = self::getContainer()->get(MarketplaceListingRepository::class);
        $company = CompanyBuilder::aCompany()->persistWith($companies);
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)
            ->persistWith($companies, $accounts);
        $actor = UserBuilder::aUser()->persistWith($users);
        $listings->replaceForAccount($company->id()->toRfc4122(), $account->id(), [
            MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($company->id())
                ->withMarketplaceAccountId($account->id())->withMarketplaceSku('SKU-1')->build(),
        ]);

        /** @var SaveDailyPlanAction $save */
        $save = self::getContainer()->get(SaveDailyPlanAction::class);
        /** @var RemoveDailyPlanAction $remove */
        $remove = self::getContainer()->get(RemoveDailyPlanAction::class);
        /** @var ReadDailyPlanAction $read */
        $read = self::getContainer()->get(ReadDailyPlanAction::class);
        $companyId = $company->id()->toRfc4122();
        $accountId = $account->id()->toRfc4122();
        $actorId = $actor->id()->toRfc4122();
        $day = new \DateTimeImmutable('2026-09-21');

        $created = $save($companyId, $accountId, 'SKU-1', $day, 12, 0, $actorId);
        self::assertSame(DailyPlanMutationOutcome::Saved, $created->outcome);
        self::assertNotNull($created->current);
        self::assertSame(1, $created->current->version);
        self::assertSame(12, $created->current->quantity);

        $changed = $save($companyId, $accountId, 'SKU-1', $day, 0, 1, $actorId);
        self::assertSame(DailyPlanMutationOutcome::Saved, $changed->outcome);
        self::assertNotNull($changed->current);
        self::assertSame(2, $changed->current->version);
        self::assertSame(0, $changed->current->quantity);

        $stale = $save($companyId, $accountId, 'SKU-1', $day, 99, 1, $actorId);
        self::assertSame(DailyPlanMutationOutcome::VersionConflict, $stale->outcome);
        self::assertNotNull($stale->current);
        self::assertSame(2, $stale->current->version);
        self::assertSame(0, $stale->current->quantity);

        $removed = $remove($companyId, $accountId, 'SKU-1', $day, 2, $actorId);
        self::assertSame(DailyPlanMutationOutcome::Saved, $removed->outcome);
        self::assertNotNull($removed->current);
        self::assertSame(3, $removed->current->version);
        self::assertNull($removed->current->quantity);

        $removedAgain = $remove($companyId, $accountId, 'SKU-1', $day, 3, $actorId);
        self::assertSame(DailyPlanMutationOutcome::Saved, $removedAgain->outcome);
        self::assertNotNull($removedAgain->current);
        self::assertSame(3, $removedAgain->current->version);

        $days = $read($companyId, $accountId, 'SKU-1', $day->modify('-1 day'), $day->modify('+1 day'));
        self::assertSame(DailyPlanMutationOutcome::Saved, $days->outcome);
        self::assertSame([0, 3, 0], array_map(static fn ($item): int => $item->version, $days->items));
        self::assertSame([null, null, null], array_map(static fn ($item): ?int => $item->quantity, $days->items));

        $restored = $save($companyId, $accountId, 'SKU-1', $day, 3, 3, $actorId);
        self::assertSame(DailyPlanMutationOutcome::Saved, $restored->outcome);
        self::assertNotNull($restored->current);
        self::assertSame(4, $restored->current->version);
        self::assertSame(3, $restored->current->quantity);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $audit = $connection->fetchAllAssociative(
            'SELECT old_quantity, new_quantity, old_version, new_version FROM planning_plan_change WHERE company_id = ? ORDER BY new_version',
            [$companyId],
        );
        self::assertSame([
            ['old_quantity' => null, 'new_quantity' => 12, 'old_version' => 0, 'new_version' => 1],
            ['old_quantity' => 12, 'new_quantity' => 0, 'old_version' => 1, 'new_version' => 2],
            ['old_quantity' => 0, 'new_quantity' => null, 'old_version' => 2, 'new_version' => 3],
            ['old_quantity' => null, 'new_quantity' => 3, 'old_version' => 3, 'new_version' => 4],
        ], array_map(self::normalizeAuditRow(...), $audit));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{old_quantity: ?int, new_quantity: ?int, old_version: int, new_version: int}
     */
    private static function normalizeAuditRow(array $row): array
    {
        $oldQuantity = $row['old_quantity'];
        $newQuantity = $row['new_quantity'];
        $oldVersion = $row['old_version'];
        $newVersion = $row['new_version'];
        \assert(null === $oldQuantity || \is_int($oldQuantity) || \is_string($oldQuantity));
        \assert(null === $newQuantity || \is_int($newQuantity) || \is_string($newQuantity));
        \assert(\is_int($oldVersion) || \is_string($oldVersion));
        \assert(\is_int($newVersion) || \is_string($newVersion));

        return [
            'old_quantity' => null === $oldQuantity ? null : (int) $oldQuantity,
            'new_quantity' => null === $newQuantity ? null : (int) $newQuantity,
            'old_version' => (int) $oldVersion,
            'new_version' => (int) $newVersion,
        ];
    }
}
