<?php

declare(strict_types=1);

namespace App\Links\Ui\Response;

final readonly class ShortLinkListResponse
{
    /**
     * @param list<ShortLinkResponse> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $pages,
        public int $page,
        public int $perPage,
    ) {
    }
}
