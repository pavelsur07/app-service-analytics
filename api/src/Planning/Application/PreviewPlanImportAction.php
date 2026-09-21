<?php

declare(strict_types=1);

namespace App\Planning\Application;

use App\Identity\Application\Facade\IdentityAccountScopeFacade;
use App\Ingestion\Application\Facade\IngestionPlanningFacade;
use App\Planning\Domain\PlanImportIssue;
use App\Planning\Domain\PlanImportPreview;
use App\Planning\Domain\PlanImportPreviewRepository;
use App\Planning\Domain\PlanImportPreviewRow;
use App\Planning\Infrastructure\Import\XlsxDailyPlanReader;
use App\Planning\Infrastructure\Query\DailyPlanVersionsQuery;
use Symfony\Component\Uid\Uuid;

final readonly class PreviewPlanImportAction
{
    public function __construct(
        private IdentityAccountScopeFacade $accounts,
        private IngestionPlanningFacade $ingestion,
        private XlsxDailyPlanReader $reader,
        private DailyPlanVersionsQuery $versions,
        private PlanImportPreviewRepository $previews,
    ) {
    }

    public function __invoke(string $companyId, string $marketplaceAccountId, string $actorId, string $path): PreviewPlanImportResult
    {
        if (!$this->accounts->ownsMarketplaceAccount($companyId, $marketplaceAccountId)) {
            return new PreviewPlanImportResult(PlanImportPreviewOutcome::AccountNotFound, null, []);
        }

        $read = $this->reader->read($path);
        $issues = $read->issues;
        $details = [];
        $uniqueSkus = array_values(array_unique(array_map(static fn ($row): string => $row->marketplaceSku, $read->rows)));
        foreach (array_chunk($uniqueSkus, 200) as $chunk) {
            array_push($details, ...$this->ingestion->knownMarketplaceSkuDetails($companyId, $marketplaceAccountId, $chunk));
        }
        $known = [];
        foreach ($details as $detail) {
            $known[$detail->marketplaceSku] = $detail->offerId;
        }
        foreach ($read->rows as $row) {
            if (!\array_key_exists($row->marketplaceSku, $known)) {
                $issues[] = new PlanImportIssue($row->rowNumber, 'marketplace_sku_unknown', 'SKU не найден в выбранном кабинете.');
            }
        }
        usort($issues, static fn (PlanImportIssue $left, PlanImportIssue $right): int => ($left->rowNumber ?? 0) <=> ($right->rowNumber ?? 0));
        if ([] !== $issues) {
            return new PreviewPlanImportResult(PlanImportPreviewOutcome::Invalid, null, $issues);
        }

        $versions = $this->versions->forRows($companyId, $marketplaceAccountId, $read->rows);
        $previewRows = [];
        foreach ($read->rows as $row) {
            $current = $versions[DailyPlanVersionsQuery::key($row->marketplaceSku, $row->businessDate)] ?? ['quantity' => null, 'version' => 0];
            $change = 0 === $current['version'] ? 'new' : ($current['quantity'] === $row->quantity ? 'unchanged' : 'changed');
            $previewRows[] = new PlanImportPreviewRow(
                $row->rowNumber, $row->marketplaceSku, $known[$row->marketplaceSku], $row->businessDate, $row->quantity,
                $current['version'], $current['quantity'], $change,
            );
        }
        $fileHash = hash_file('sha256', $path);
        if (false === $fileHash) {
            throw new \RuntimeException('Не удалось рассчитать fingerprint файла.');
        }
        $preview = PlanImportPreview::create(
            Uuid::fromString($companyId), Uuid::fromString($marketplaceAccountId), Uuid::fromString($actorId),
            hash('sha256', $companyId."\0".$marketplaceAccountId."\0".$fileHash), $previewRows, new \DateTimeImmutable(),
        );
        $this->previews->add($preview);

        return new PreviewPlanImportResult(PlanImportPreviewOutcome::Ready, $preview, []);
    }
}
