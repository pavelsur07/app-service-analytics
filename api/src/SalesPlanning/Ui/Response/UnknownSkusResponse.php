<?php

declare(strict_types=1);

namespace App\SalesPlanning\Ui\Response;

/**
 * 422 при вводе SKU, которых нет в каталоге подключения: какие именно,
 * чтобы экран подсветил строки, а не отказал всему вводу молча.
 */
final readonly class UnknownSkusResponse
{
    /**
     * @param list<string> $unknownSkus
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
        public array $unknownSkus,
    ) {
    }
}
