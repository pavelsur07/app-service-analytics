<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Query;

/** Whitelisted SQL direction for the buyout-rate keyset order. */
enum BuyoutRateDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';

    public function sql(): string
    {
        return self::Asc === $this ? 'ASC' : 'DESC';
    }

    public function beyond(): string
    {
        return self::Asc === $this ? '>' : '<';
    }
}
