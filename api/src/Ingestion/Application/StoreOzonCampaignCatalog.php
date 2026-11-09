<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Domain\OzonCampaignCatalogParser;
use App\Ingestion\Domain\OzonCampaignCatalogRepository;
use App\Ingestion\Domain\OzonCampaignCatalogSnapshot;
use App\Shared\Domain\ExceptionTracker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class StoreOzonCampaignCatalog
{
    public function __construct(private OzonCampaignCatalogParser $parser, private OzonCampaignCatalogRepository $catalog, private LoggerInterface $logger, private ExceptionTracker $errors)
    {
    }

    public function store(string $companyId, Uuid $accountId, Uuid $rawId, \DateTimeImmutable $receivedAt, string $body, bool $restored = false): bool
    {
        try {
            $data = $this->parser->parse($body);
        } catch (\UnexpectedValueException|\JsonException $failure) {
            $this->logger->warning('Campaign catalog projection rejected; saved raw is retained', ['company_id' => $companyId, 'marketplace_account_id' => $accountId->toRfc4122(), 'raw_document_id' => $rawId->toRfc4122(), 'exception_class' => $failure::class]);
            // Parser messages are fixed; never send the raw response or a previous exception.
            $this->errors->capture(new \UnexpectedValueException('Saved Ozon campaign catalog could not be parsed.'));

            return false;
        }
        $this->catalog->replace($companyId, new OzonCampaignCatalogSnapshot(Uuid::fromString($companyId), $accountId, $rawId, $receivedAt, $data, $restored));

        return true;
    }
}
