<?php

declare(strict_types=1);

namespace App\PriceMonitoring\Ui\Response;

/**
 * Только сами артикулы. Состояние в ответ не входит: список отдаёт
 * активные и никаких других, и поле, у которого всегда одно значение, —
 * шум в контракте, который потребитель однажды примет за настоящий
 * признак.
 */
final readonly class TrackedSkuListResponse
{
    /**
     * @param list<string> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
