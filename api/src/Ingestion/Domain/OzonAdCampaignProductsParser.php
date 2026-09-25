<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разбор `GET /api/client/campaign/{id}/v2/products` — только SKU:
 * проба рекламного ключа сверяет их с каталогом подключения
 * (ADR-026, п. 1).
 */
final class OzonAdCampaignProductsParser
{
    /**
     * @return list<string>
     */
    public function skus(string $body): array
    {
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !\is_array($decoded['products'] ?? null)) {
            throw new \UnexpectedValueException('Ozon campaign products: no "products" array.');
        }

        $skus = [];
        foreach ($decoded['products'] as $product) {
            $sku = \is_array($product) ? ($product['sku'] ?? null) : null;
            if (!\is_string($sku) || 1 !== preg_match('/\A[0-9]{1,20}\z/', $sku)) {
                throw new \UnexpectedValueException('Ozon campaign products: sku is not a digit string.');
            }
            $skus[] = $sku;
        }

        return $skus;
    }
}
