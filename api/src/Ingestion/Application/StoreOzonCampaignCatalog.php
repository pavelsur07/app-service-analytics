<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Domain\OzonCampaignCatalogParser;
use App\Ingestion\Domain\OzonCampaignCatalogRepository;
use App\Ingestion\Domain\OzonCampaignCatalogSnapshot;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class StoreOzonCampaignCatalog
{
    public function __construct(private OzonCampaignCatalogParser $parser, private OzonCampaignCatalogRepository $catalog, private LoggerInterface $logger)
    {
    }

    public function store(string $companyId, Uuid $accountId, Uuid $rawId, \DateTimeImmutable $receivedAt, string $body): bool
    {
        try {
            $data = $this->parser->parse($body);
        } catch (\UnexpectedValueException|\JsonException $failure) {
            $this->logger->warning('Campaign catalog projection rejected; saved raw is retained', ['exception_class' => $failure::class]);

            return false;
        }
        $this->catalog->replace($companyId, new OzonCampaignCatalogSnapshot(Uuid::fromString($companyId), $accountId, $rawId, $receivedAt, $data));

        return true;
    }
}
