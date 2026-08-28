<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/**
 * Курсор страницы отчёта: пара «значение сортировки, артикул» вместе
 * с тем порядком, в котором она была снята.
 *
 * Пара, а не одно значение: у товаров без продаж выручка нулевая у всех,
 * и курсор по одному столбцу перескакивал бы строки. Подделать курсор
 * можно только в границах своей компании — companyId в запросе остаётся.
 *
 * Порядок входит в курсор, потому что точка «после строки X» осмысленна
 * только внутри того порядка, в котором X стояла. Курсор, снятый при
 * сортировке по выручке, при сортировке по марже указывал бы на другое
 * место — и страница вышла бы правдоподобной, но неверной. Клиент
 * сбрасывает курсор при смене сортировки сам, но защита на клиенте
 * защитой не является.
 */
final readonly class UnitEconomicsCursor
{
    public function __construct(
        public UnitEconomicsSort $sort,
        public UnitEconomicsDirection $direction,
        public int $sortValue,
        public string $marketplaceSku,
    ) {
    }

    /**
     * Форма «сортировка:направление:значение:артикул» — читаемая
     * и без base64: кодировать нечего, а непрозрачность ради
     * единообразия мешала бы читать логи (docs/patterns.md,
     * «Форма курсора»).
     *
     * Предел разбора — четыре части: артикул забирает весь остаток,
     * даже если содержит двоеточие.
     */
    public static function fromString(string $raw): ?self
    {
        $parts = explode(':', $raw, 4);
        if (4 !== \count($parts)) {
            return null;
        }

        [$sort, $direction, $value, $sku] = $parts;

        if (1 !== preg_match('/^-?\d+$/', $value) || '' === $sku) {
            return null;
        }

        $sortCase = UnitEconomicsSort::tryFrom($sort);
        $directionCase = UnitEconomicsDirection::tryFrom($direction);

        if (null === $sortCase || null === $directionCase) {
            return null;
        }

        return new self($sortCase, $directionCase, (int) $value, $sku);
    }

    public function toString(): string
    {
        return implode(':', [
            $this->sort->value,
            $this->direction->value,
            (string) $this->sortValue,
            $this->marketplaceSku,
        ]);
    }

    /**
     * Снят ли курсор в том же порядке, в котором его сейчас применяют.
     * Несовпадение — отказ, а не молчаливая выдача чужой страницы.
     */
    public function matches(UnitEconomicsSort $sort, UnitEconomicsDirection $direction): bool
    {
        return $this->sort === $sort && $this->direction === $direction;
    }

    /**
     * Условие «строго после курсора» под сортировку
     * «<колонка> <направление>, marketplace_sku ASC».
     *
     * Здесь, а не копией в каждом запросе, потому что копия уже успела
     * разъехаться с сортировкой: кортежное `(сумма, артикул) < (:сумма,
     * :артикул)` означает «сумма меньше ИЛИ равна и артикул меньше», а
     * при равной сумме порядок идёт по возрастанию артикула, то есть
     * нужен больше. Строки с бо́льшим артикулом пропадали, с меньшим —
     * повторялись на следующей странице. Задевало не край, а самый
     * частый случай: у карточек без продаж выручка нулевая у всех,
     * и равенство сумм там сплошное.
     *
     * Артикул сравнивается через `>` при любом направлении: вторым
     * столбцом он всегда идёт по возрастанию, иначе тай-брейк перестал
     * бы быть тай-брейком и порядок снова стал бы неустойчивым.
     */
    public function after(): string
    {
        return \sprintf(
            '(%1$s %2$s :cursorValue OR (%1$s = :cursorValue AND marketplace_sku > :cursorSku))',
            $this->sort->column(),
            $this->direction->beyond(),
        );
    }
}
