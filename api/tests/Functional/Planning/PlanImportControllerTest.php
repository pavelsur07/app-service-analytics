<?php

declare(strict_types=1);

namespace App\Tests\Functional\Planning;

use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\MarketplaceAccount;
use App\Identity\Domain\MarketplaceAccountRepository;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Ingestion\Domain\MarketplaceListingRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\MarketplaceAccountBuilder;
use App\Tests\Support\Builder\MarketplaceListingBuilder;
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
        foreach ($this->files as $file) { @unlink($file); }
        parent::tearDown();
    }

    public function testValidFileCreatesPreviewWithoutChangingPlan(): void
    {
        $client = static::createClient();
        [$company, $account] = $this->scope($client, ['SKU-1', 'SKU-2']);
        $file = $this->xlsx([
            ['SKU', 'Дата', 'План, шт.'],
            ['SKU-1', '2026-09-22', 12],
            ['SKU-2', '2026-09-23', 0],
        ]);

        $payload = $this->upload($client, $company, $account, $file, 200);

        self::assertIsString($payload['previewId']);
        self::assertSame(['total' => 2, 'new' => 2, 'changed' => 0, 'unchanged' => 0], $payload['summary']);
        self::assertCount(2, $payload['items']);
        self::assertSame([], $payload['issues']);
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertSame(0, (int) $entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM planning_daily_plan WHERE company_id = ?', [$company->id()->toRfc4122()]));
    }

    public function testInvalidAndUnknownRowsReturnAllErrorsWithoutPreview(): void
    {
        $client = static::createClient();
        [$company, $account] = $this->scope($client, ['SKU-1']);
        $file = $this->xlsx([
            ['SKU', 'Дата', 'План, шт.'],
            ['SKU-1', '2026-02-30', -1],
            ['UNKNOWN', '2026-09-23', 2],
        ]);

        $payload = $this->upload($client, $company, $account, $file, 422);

        self::assertNull($payload['previewId']);
        self::assertSame([[2, 'date_invalid'], [2, 'quantity_invalid'], [3, 'marketplace_sku_unknown']], array_map(static fn (array $issue): array => [$issue['rowNumber'], $issue['code']], $payload['issues']));
    }

    public function testApplyIsAtomicOnConflictAndIdempotentAfterSuccess(): void
    {
        $client = static::createClient();
        [$company, $account] = $this->scope($client, ['SKU-1', 'SKU-2']);
        $file = $this->xlsx([
            ['SKU', 'Дата', 'План, шт.'],
            ['SKU-1', '2026-09-22', 12],
            ['SKU-2', '2026-09-23', 8],
        ]);
        $preview = $this->upload($client, $company, $account, $file, 200);
        self::assertIsString($preview['previewId']);

        $client->request('PUT', \sprintf('/api/companies/%s/planning/accounts/%s/skus/SKU-2/plan/2026-09-23', $company->id(), $account->id()), server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['quantity' => 7, 'expectedVersion' => 0], JSON_THROW_ON_ERROR));
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $conflict = $this->apply($client, $company, $account, $preview['previewId'], 409);
        self::assertSame('import_version_conflict', $conflict['code']);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM planning_daily_plan WHERE company_id = ? AND marketplace_sku = ?', [$company->id()->toRfc4122(), 'SKU-1']));

        $fresh = $this->upload($client, $company, $account, $file, 200);
        self::assertIsString($fresh['previewId']);
        $applied = $this->apply($client, $company, $account, $fresh['previewId'], 200);
        self::assertSame(['created' => 1, 'updated' => 1, 'unchanged' => 0], $applied['summary']);
        self::assertSame($applied, $this->apply($client, $company, $account, $fresh['previewId'], 200));
        self::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM planning_daily_plan WHERE company_id = ?', [$company->id()->toRfc4122()]));
        self::assertSame(3, (int) $connection->fetchOne('SELECT COUNT(*) FROM planning_plan_change WHERE company_id = ?', [$company->id()->toRfc4122()]));
    }

    /** @param list<string> $skus @return array{Company, MarketplaceAccount} */
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
        /** @var MarketplaceAccountRepository $accounts */
        $accounts = static::getContainer()->get(MarketplaceAccountRepository::class);
        $account = MarketplaceAccountBuilder::aMarketplaceAccount()->withCompany($company)->persistWith($this->companies(), $accounts);
        /** @var MarketplaceListingRepository $listings */
        $listings = static::getContainer()->get(MarketplaceListingRepository::class);
        $listings->replaceForAccount($company->id()->toRfc4122(), $account->id(), array_map(
            static fn (string $sku) => MarketplaceListingBuilder::aMarketplaceListing()->withCompanyId($company->id())->withMarketplaceAccountId($account->id())->withMarketplaceSku($sku)->build(),
            $skus,
        ));

        return [$company, $account];
    }

    /** @param list<list<mixed>> $rows */
    private function xlsx(array $rows): string
    {
        $file = tempnam(sys_get_temp_dir(), 'planning-upload-');
        self::assertIsString($file);
        $this->files[] = $file;
        $writer = new Writer();
        $writer->openToFile($file);
        foreach ($rows as $row) { $writer->addRow(Row::fromValues($row)); }
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
        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

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
        $payload = json_decode($content, true, flags: JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function companies(): CompanyRepository
    {
        /** @var CompanyRepository $companies */
        $companies = static::getContainer()->get(CompanyRepository::class);

        return $companies;
    }
}
