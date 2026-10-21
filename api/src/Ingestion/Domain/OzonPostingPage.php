<?php

declare(strict_types=1);

namespace App\Ingestion\Domain;

/**
 * Признаки продолжения страницы `/v3/posting/fbo/list` (ADR-042):
 * обработчику нужно знать, спрашивать ли следующую и с каким курсором,
 * не разбирая JSON самому. Отправления разбирают парсеры.
 *
 * Только v3: в v2 курсора не было, и тело v2 сюда не попадает —
 * старые raw читают парсеры, не листание.
 */
final readonly class OzonPostingPage
{
    private function __construct(
        public bool $hasNext,
        public string $cursor,
    ) {
    }

    public static function fromBody(string $rawBody): self
    {
        $decoded = json_decode($rawBody, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded) || !\is_array($decoded['postings'] ?? null)) {
            throw new \UnexpectedValueException('Ozon /v3/posting/fbo/list response must contain a "postings" array.');
        }

        $hasNext = $decoded['has_next'] ?? null;
        if (!\is_bool($hasNext)) {
            throw new \UnexpectedValueException('Ozon /v3/posting/fbo/list response must contain a boolean "has_next".');
        }

        $cursor = $decoded['cursor'] ?? '';
        if (!\is_string($cursor)) {
            throw new \UnexpectedValueException('Ozon /v3/posting/fbo/list "cursor" must be a string.');
        }
        if ($hasNext && '' === $cursor) {
            throw new \UnexpectedValueException('Ozon /v3/posting/fbo/list page has_next=true has no cursor.');
        }

        return new self($hasNext, $cursor);
    }
}
