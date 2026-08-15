<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Разбор /v3/product/info/list — наименования карточек. Domain,
 * не Infrastructure: ни одного вызова наружу. Проверяется на снятой
 * с боевого кабинета фикстуре (CLAUDE.md §9).
 *
 * Отдаёт отображение «sku → наименование». Склейка по sku, а не
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
 * Позиция без наименования пропускается, а не роняет разбор. Имя
 * у карточки Ozon обязательное, и его отсутствие означало бы не «товар
 * без имени», а неполный ответ; писатель в этом случае оставит
 * известное имя, а не затрёт его пустотой.
 */
final class OzonProductInfoListParser
{
    /**
     * @return array<string, string> наименование по sku
     */
    public function parse(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !isset($decoded['items']) || !\is_array($decoded['items'])) {
            throw new \UnexpectedValueException('Ozon /v3/product/info/list response must contain an "items" array.');
        }

        $names = [];
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

            $names[$sku] = $name;
        }

        return $names;
    }
}
