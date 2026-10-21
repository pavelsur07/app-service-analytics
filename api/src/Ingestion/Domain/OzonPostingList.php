<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Список отправлений из тела выгрузки продаж FBO — в обоих форматах,
 * которые лежат в raw под одним типом отчёта (ADR-042 п. 2):
 * `result` — `/v2/posting/fbo/list` (отключён Ozon 2026-10-19),
 * `postings` — `/v3/posting/fbo/list`. Raw неизменяем (ADR-006),
 * и разбор старых тел нужен backfill статусов и истории выкупа.
 */
final class OzonPostingList
{
    private function __construct()
    {
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    public static function postings(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException('Ozon posting list response must be an object.');
        }

        $list = $decoded['postings'] ?? $decoded['result'] ?? null;
        if (!\is_array($list) || !array_is_list($list)) {
            throw new \UnexpectedValueException('Ozon posting list response must contain a "postings" (v3) or "result" (v2) array.');
        }

        $postings = [];
        foreach ($list as $posting) {
            if (!\is_array($posting)) {
                throw new \UnexpectedValueException('Ozon posting entry must be an object.');
            }
            $postings[] = $posting;
        }

        return $postings;
    }
}
