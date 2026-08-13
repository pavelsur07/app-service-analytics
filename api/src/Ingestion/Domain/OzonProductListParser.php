<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разбор /v3/product/list в артикулы каталога. Domain, не Infrastructure:
 * ни одного вызова наружу — ни HTTP, ни БД. Проверяется на зафиксированной
 * фикстуре (CLAUDE.md §9), снятой с настоящего кабинета.
 *
 * Берём только sku и ничего больше. Название и цена в каталоге не нужны:
 * он отвечает ровно на один вопрос — «этот товар наш?». Появится, где
 * их показывать, — добавится колонка, это дёшево (MarketplaceListing).
 *
 * Товар без sku пропускается, а не роняет разбор: sku = 0 у товара,
 * которому площадка ещё не завела карточку. Такой товар нельзя встретить
 * на сайте, и в каталоге «своих карточек» ему делать нечего. Молчаливым
 * пропуском это не назвать — их число возвращается вызывающему коду,
 * и обработчик пишет его в лог синхронизации.
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

        $skus = [];
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

            $skus[] = $sku;
        }

        $lastId = $result['last_id'] ?? '';
        if (!\is_string($lastId)) {
            throw new \UnexpectedValueException('Ozon /v3/product/list result.last_id must be a string.');
        }

        return new OzonProductListPage(
            skus: array_values(array_unique($skus)),
            lastId: $lastId,
            itemsOnPage: \count($items),
        );
    }
}
