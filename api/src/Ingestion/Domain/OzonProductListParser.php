<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разбор /v3/product/list в артикулы каталога. Domain, не Infrastructure:
 * ни одного вызова наружу — ни HTTP, ни БД. Проверяется на зафиксированной
 * фикстуре (CLAUDE.md §9), снятой с настоящего кабинета.
 *
 * Берём sku, артикул продавца и product_id. Наименования в этом ответе
 * нет вовсе — оно приходит вторым запросом (OzonProductInfoListParser).
 *
 * Товар без sku пропускается, а не роняет разбор: sku = 0 у товара,
 * которому площадка ещё не завела карточку. Такой товар нельзя встретить
 * на сайте, и в каталоге «своих карточек» ему делать нечего. Молчаливым
 * пропуском это не назвать — их число возвращается вызывающему коду,
 * и обработчик пишет его в лог синхронизации.
 *
 * Артикул продавца, наоборот, роняет разбор, если его нет: он у товара
 * обязателен, им товар заводят в кабинете, и пустая строка вместо него
 * означала бы карточку, которую селлер не опознает на экране.
 */
final class OzonProductListParser
{
    public function parse(string $rawBody): OzonProductListPage
    {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['result']) || !\is_array($decoded['result'])) {
            throw new \UnexpectedValueException('Ozon /v3/product/list response must contain a "result" object.');
        }

        $result = $decoded['result'];
        $items = $result['items'] ?? [];
        if (!\is_array($items)) {
            throw new \UnexpectedValueException('Ozon /v3/product/list result.items must be an array.');
        }

        /** @var array<string, OzonProductListItem> $parsed */
        $parsed = [];
        foreach ($items as $item) {
            if (!\is_array($item)) {
                throw new \UnexpectedValueException('Ozon product entry must be an object.');
            }

            $sku = $item['sku'] ?? null;
            if (!\is_int($sku) && !\is_string($sku)) {
                throw new \UnexpectedValueException('Ozon product sku must be a number or a string.');
            }

            $sku = (string) $sku;
            if ('' === $sku || '0' === $sku) {
                continue;
            }

            $offerId = $item['offer_id'] ?? null;
            if (!\is_string($offerId) || '' === $offerId) {
                throw new \UnexpectedValueException("Ozon product {$sku} has no offer_id — the seller's own article is mandatory.");
            }

            $productId = $item['product_id'] ?? null;
            if (!\is_int($productId)) {
                throw new \UnexpectedValueException("Ozon product {$sku} product_id must be an integer.");
            }

            // Ключ массива, а не array_unique по объектам: дубль sku
            // в одной странице означал бы две карточки с одним артикулом
            // площадки, и брать первую — единственное осмысленное
            // поведение (вторая всё равно та же карточка).
            $parsed[$sku] ??= new OzonProductListItem($sku, $offerId, $productId);
        }

        $lastId = $result['last_id'] ?? '';
        if (!\is_string($lastId)) {
            throw new \UnexpectedValueException('Ozon /v3/product/list result.last_id must be a string.');
        }

        return new OzonProductListPage(
            items: array_values($parsed),
            lastId: $lastId,
            itemsOnPage: \count($items),
        );
    }
}
