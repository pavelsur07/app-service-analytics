<?php

declare(strict_types=1);

namespace App\Links\Ui\Response;

use App\Links\Domain\ShortLink;
use App\Links\Infrastructure\Query\AdminShortLinkRow;

final readonly class ShortLinkResponse
{
    public function __construct(
        public string $id,
        public string $code,
        public string $shortUrl,
        public string $name,
        public string $targetUrl,
        public string $status,
        public int $version,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }

    public static function fromEntity(ShortLink $link, string $publicBaseUrl): self
    {
        return new self(
            id: $link->id()->toRfc4122(),
            code: $link->code(),
            shortUrl: self::shortUrl($publicBaseUrl, $link->code()),
            name: $link->name(),
            targetUrl: $link->targetUrl(),
            status: $link->status()->value,
            version: $link->version(),
            createdAt: $link->createdAt()->format(\DATE_ATOM),
            updatedAt: $link->updatedAt()->format(\DATE_ATOM),
        );
    }

    public static function fromRow(AdminShortLinkRow $link, string $publicBaseUrl): self
    {
        return new self(
            id: $link->id,
            code: $link->code,
            shortUrl: self::shortUrl($publicBaseUrl, $link->code),
            name: $link->name,
            targetUrl: $link->targetUrl,
            status: $link->status,
            version: $link->version,
            createdAt: $link->createdAt,
            updatedAt: $link->updatedAt,
        );
    }

    private static function shortUrl(string $publicBaseUrl, string $code): string
    {
        return rtrim($publicBaseUrl, '/').'/'.$code;
    }
}
