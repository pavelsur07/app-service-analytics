<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/** Whitelisted metadata only; budgets and provider strategy enums are ignored. */
final class OzonCampaignCatalogParser
{
    public function parse(string $body): OzonCampaignCatalogData
    {
        if (\strlen($body) > 8 * 1024 * 1024) {
            throw new \UnexpectedValueException('Campaign catalog is too large.');
        }
        $data = json_decode($body, true, 64, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        if (!\is_array($data) || !\is_array($data['list'] ?? null) || !array_is_list($data['list']) || \count($data['list']) > OzonCampaignCatalogData::MAX_ITEMS) {
            throw new \UnexpectedValueException('Invalid campaign catalog list.');
        }
        $items = [];
        $seen = [];
        foreach ($data['list'] as $row) {
            if (!\is_array($row) || array_is_list($row)) {
                throw new \UnexpectedValueException('Invalid campaign catalog row.');
            }
            $item = OzonCampaignCatalogItem::fromRaw($row);
            if (isset($seen[$item->id])) {
                throw new \UnexpectedValueException('Duplicate campaign catalog ID.');
            }
            $seen[$item->id] = true;
            $items[] = $item;
        }
        $total = $data['total'] ?? null;
        if (\is_string($total) && 1 === preg_match('/\A[0-9]{1,9}\z/', $total)) {
            $total = (int) $total;
        }
        if (null !== $total && (!\is_int($total) || $total < \count($items) || $total > 999999999)) {
            throw new \UnexpectedValueException('Invalid campaign catalog total.');
        }

        return new OzonCampaignCatalogData($items, $total, null !== $total && $total === \count($items));
    }
}
