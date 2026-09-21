<?php

declare(strict_types=1);

namespace App\Tests\Functional\Planning;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Ingestion\Domain\SalesFactRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
use App\Tests\Support\Builder\SalesFactBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PlanImportControllerTest extends WebTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testValidFileCreatesPreviewWithoutChangingPlan(): void
    {
        $client = static::createClient();
        [$company, $account] = $this->scope($client, ['SKU-1', 'SKU-2']);
        $file = \dirname(__DIR__, 2).'/Fixtures/Planning/daily-plan-valid.xlsx';

        $payload = $this->upload($client, $company, $account, $file, 200);

        self::assertIsString($payload['previewId']);
        self::assertSame(['total' => 2, 'new' => 2, 'changed' => 0, 'unchanged' => 0], $payload['summary']);
        $items = $payload['items'];
        self::assertIsArray($items);
        self::assertCount(2, $items);
        self::assertIsArray($items[0]);
        self::assertSame('article-SKU-1', $items[0]['sellerArticle']);
        self::assertSame([], $payload['issues']);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, $this->dbCount($entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM planning_daily_plan WHERE company_id = ?', [$company->id()->toRfc4122()])));
    }

    public function testInvalidAndUnknownRowsReturnAllErrorsWithoutPreview(): void
    {
        $client = static::createClient();
        [$company, $account] = $this->scope($client, ['SKU-1']);
        $otherAccount = $this->account($company, 'other-import-shop');
        $this->listings($company, $otherAccount, ['UNKNOWN']);
        $file = $this->xlsx([
            ['SKU', 'Дата', 'План, шт.'],
            ['SKU-1', '2026-02-30', -1],
            ['UNKNOWN', 'invalid-date', -2],
        ]);

        $payload = $this->upload($client, $company, $account, $file, 422);

        self::assertNull($payload['previewId']);
        $issues = $payload['issues'];
        self::assertIsArray($issues);
        $actual = [];
        foreach ($issues as $issue) {
            self::assertIsArray($issue);
            self::assertIsInt($issue['rowNumber'] ?? null);
            self::assertIsString($issue['code'] ?? null);
            $actual[] = [$issue['rowNumber'], $issue['code']];
        }
        self::assertSame([
            [2, 'date_invalid'], [2, 'quantity_invalid'],
            [3, 'date_invalid'], [3, 'quantity_invalid'], [3, 'marketplace_sku_unknown'],
        ], $actual);
    }

    public function testHistoricalSkuWithoutCurrentListingRemainsAvailableForImport(): void
    {
        $client = static::createClient();
        [$company, $account] = $this->scope($client, []);
        /** @var SalesFactRepository $sales */
        $sales = static::getContainer()->get(SalesFactRepository::class);
        SalesFactBuilder::aSalesFact()->withCompanyId($company->id())->withMarketplaceAccountId($account->id())
            ->withSourceRowId('HISTORIC-IMPORT|SKU-HISTORIC')->withPostingNumber('HISTORIC-IMPORT')
            ->withOrderNumber('HISTORIC-IMPORT')->withMarketplaceSku('SKU-HISTORIC')->persistWith($sales);
        $file = $this->xlsx([
            ['SKU', 'Дата', 'План, шт.'],
            ['SKU-HISTORIC', '2026-09-22', 4],
        ]);

        $payload = $this->upload($client, $company, $account, $file, 200);

        self::assertSame([], $payload['issues']);
        $items = $payload['items'];
        self::assertIsArray($items);
        self::assertIsArray($items[0]);
        self::assertNull($items[0]['sellerArticle']);
    }

    public function testInvalidUploadUsesPreviewErrorContract(): void
    {
        $client = static::createClient();
        [$company, $account] = $this->scope($client, []);
        $file = tempnam(sys_get_temp_dir(), 'planning-invalid-');
        self::assertIsString($file);
        $this->files[] = $file;
        file_put_contents($file, 'not an xlsx');

        $client->request('POST', \sprintf('/api/companies/%s/planning/accounts/%s/imports/preview', $company->id(), $account->id()), files: [
            'file' => new UploadedFile($file, 'plan.csv', 'text/csv', null, true),
        ]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame(['total' => 0, 'new' => 0, 'changed' => 0, 'unchanged' => 0], $payload['summary']);
        self::assertSame([], $payload['items']);
        $issues = $payload['issues'];
        self::assertIsArray($issues);
        self::assertIsArray($issues[0]);
        self::assertSame('xlsx_file_required', $issues[0]['code']);
    }

    public function testApplyIsAtomicOnConflictAndIdempotentAfterSuccess(): void
    {
        $client = static::createClient();
        [$company, $account, $owner] = $this->scope($client, ['SKU-1', 'SKU-2']);
        $file = $this->xlsx([
            ['SKU', 'Дата', 'План, шт.'],
            ['SKU-1', '2026-09-22', 12],
            ['SKU-2', '2026-09-23', 8],
        ]);
        $preview = $this->upload($client, $company, $account, $file, 200);
        self::assertIsString($preview['previewId']);

        $otherMember = $this->member($company, 'other-import-member@example.com');
        $client->loginUser($otherMember, 'api');
        $hiddenFromOtherMember = $this->apply($client, $company, $account, $preview['previewId'], 404);
        self::assertSame('import_preview_not_found', $hiddenFromOtherMember['code']);
        $client->loginUser($owner, 'api');

        $otherAccount = $this->account($company, 'other-apply-shop');
        $hidden = $this->apply($client, $company, $otherAccount, $preview['previewId'], 404);
        self::assertSame('import_preview_not_found', $hidden['code']);

        $client->request('PUT', \sprintf('/api/companies/%s/planning/accounts/%s/skus/SKU-2/plan/2026-09-23', $company->id(), $account->id()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['quantity' => 7, 'expectedVersion' => 0], \JSON_THROW_ON_ERROR));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $conflict = $this->apply($client, $company, $account, $preview['previewId'], 409);
        self::assertSame('import_version_conflict', $conflict['code']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        self::assertSame(0, $this->dbCount($connection->fetchOne('SELECT COUNT(*) FROM planning_daily_plan WHERE company_id = ? AND marketplace_sku = ?', [$company->id()->toRfc4122(), 'SKU-1'])));

        $fresh = $this->upload($client, $company, $account, $file, 200);
        self::assertIsString($fresh['previewId']);
        $applied = $this->apply($client, $company, $account, $fresh['previewId'], 200);
        self::assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0], $applied['summary']);
        self::assertSame($applied, $this->apply($client, $company, $account, $fresh['previewId'], 200));
        self::assertSame(2, $this->dbCount($connection->fetchOne('SELECT COUNT(*) FROM planning_daily_plan WHERE company_id = ?', [$company->id()->toRfc4122()])));
        self::assertSame(3, $this->dbCount($connection->fetchOne('SELECT COUNT(*) FROM planning_plan_change WHERE company_id = ?', [$company->id()->toRfc4122()])));
    }

    /**
     * @param list<string> $skus
     *
     * @return array{0: Company, 1: MarketplaceAccount, 2: User}
     */
    private function scope(KernelBrowser $client, array $skus): array
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users = new DoctrineUserRepository($entityManager);
        $members = new DoctrineCompanyMemberRepository($entityManager);
        $company = CompanyBuilder::aCompany()->persistWith($this->companies());
        $user = UserBuilder::aUser()->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)->persistWith($this->companies(), $users, $members);
        $client->loginUser($user, 'api');
        $account = $this->account($company, 'primary-import-shop');
        $this->listings($company, $account, $skus);

        return [$company, $account, $user];
    }

    private function member(Company $company, string $email): User
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $users = new DoctrineUserRepository($entityManager);
        $members = new DoctrineCompanyMemberRepository($entityManager);
        $user = UserBuilder::aUser()->withEmail($email)->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()->withCompany($company)->withUser($user)
            ->persistWith($this->companies(), $users, $members);

        return $user;
    }

    private function account(Company $company, string $externalShopId): MarketplaceAccount
    {
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);

        return MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)->withExternalShopId($externalShopId)
            ->persistWith($this->companies(), $accounts);
    }

    /** @param list<string> $skus */
    private function listings(Company $company, MarketplaceAccount $account, array $skus): void
    {
        /** @var MarketplaceListingRepository $listings */
        $listings = static::getContainer()->get(MarketplaceListingRepository::class);
        $listings->replaceForAccount($company->id()->toRfc4122(), $account->id(), array_map(
            static fn (string $sku) => MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($company->id())->withMarketplaceAccountId($account->id())->withMarketplaceSku($sku)->withOfferId('article-'.$sku)->build(),
            $skus,
        ));
    }

    /** @param list<list<bool|float|int|string|\DateTimeInterface|null>> $rows */
    private function xlsx(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'planning-upload-');
        self::assertIsString($file);
        $this->files[] = $file;
        $writer = new Writer();
        $writer->openToFile($file);
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row));
        }
        $writer->close();

        return $file;
    }

    /** @return array<string, mixed> */
    private function upload(KernelBrowser $client, Company $company, MarketplaceAccount $account, string $file, int $status): array
    {
        $client->request('POST', \sprintf('/api/companies/%s/planning/accounts/%s/imports/preview', $company->id(), $account->id()), files: [
            'file' => new UploadedFile($file, 'plan.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
        self::assertSame($status, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    /** @return array<string, mixed> */
    private function apply(KernelBrowser $client, Company $company, MarketplaceAccount $account, string $previewId, int $status): array
    {
        $client->request('POST', \sprintf('/api/companies/%s/planning/accounts/%s/imports/%s/apply', $company->id(), $account->id(), $previewId));
        self::assertSame($status, $client->getResponse()->getStatusCode());
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return $companies;
    }

    private function dbCount(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new \UnexpectedValueException('COUNT вернул некорректное значение.');
        }

        return (int) $value;
    }
}
