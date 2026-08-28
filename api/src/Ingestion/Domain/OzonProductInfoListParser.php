<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разбор /v3/product/info/list — карточка товара: наименование и фото.
 * Domain,
 * не Infrastructure: ни одного вызова наружу. Проверяется на снятой
 * с боевого кабинета фикстуре (CLAUDE.md §9).
 *
 * Отдаёт отображение «sku → карточка». Склейка по sku, а не
 * по product_id, хотя запрос уходит с product_id: sku — то, чем карточка
 * опознаётся в нашей таблице и в фактах продаж, и второй идентификатор
 * в обороте был бы лишним поводом их перепутать. Проверено на паре
 * фикстур одного дня: 62 из 62 позиций сходятся по sku.
 *
 * Ответ содержит куда больше — цены, комиссии, штрихкоды, остатки.
 * Ничего из этого не берётся: заводить колонки, которые некуда
 * показать, значит хранить данные, за верностью которых никто
 * не следит.
 *
 * Фото — исключение из этого правила, и оно оформлено, а не сделано
 * молча: колонку под него есть куда показать (превью в таблице
 * юнит-экономики), и добыть адрес иначе нельзя. Медиа-идентификатор
 * в нём не выводится ни из sku, ни из offer_id, ни из product_id,
 * а шард бакета меняется от карточки к карточке — проверено на всех
 * 62 позициях фикстуры. Либо берём отсюда, либо фото нет вовсе.
 *
 * Кривой формат фото даёт null, а не исключение, — и это осознанно
 * иначе, чем у цены. OzonListingPriceParser бросает потому, что молча
 * записанный ноль заморозил бы старую цену как текущую. Здесь же
 * исключение уронило бы синхронизацию каталога целиком — вместе
 * с именами, артикулами и историей цен — ради украшения. Единственная
 * жёсткая проверка: адрес уходит в атрибут src, поэтому принимается
 * только https.
 *
 * Позиция без наименования пропускается, а не роняет разбор. Имя
 * у карточки Ozon обязательное, и его отсутствие означало бы не «товар
 * без имени», а неполный ответ; писатель в этом случае оставит
 * известное имя, а не затрёт его пустотой.
 */
final class OzonProductInfoListParser
{
    /**
     * @return array<string, OzonListingCard> карточка по sku
     */
    public function parse(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['items']) || !\is_array($decoded['items'])) {
            throw new \UnexpectedValueException('Ozon /v3/product/info/list response must contain an "items" array.');
        }

        $cards = [];
        foreach ($decoded['items'] as $item) {
            if (!\is_array($item)) {
                throw new \UnexpectedValueException('Ozon product info entry must be an object.');
            }

            $sku = $item['sku'] ?? null;
            if (!\is_int($sku) && !\is_string($sku)) {
                throw new \UnexpectedValueException('Ozon product info sku must be a number or a string.');
            }

            $sku = (string) $sku;
            if ('' === $sku || '0' === $sku) {
                continue;
            }

            $name = $item['name'] ?? null;
            if (!\is_string($name) || '' === $name) {
                continue;
            }

            $cards[$sku] = new OzonListingCard($name, self::photoUrl($item));
        }

        return $cards;
    }

    /**
     * `primary_image` приходит массивом из одной строки, а не строкой.
     *
     * @param array<mixed> $item
     */
    private static function photoUrl(array $item): ?string
    {
        $images = $item['primary_image'] ?? null;
        if (!\is_array($images)) {
            return null;
        }

        $url = $images[0] ?? null;
        if (!\is_string($url) || !str_starts_with($url, 'https://')) {
            return null;
        }

        return $url;
    }
}
