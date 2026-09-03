<?php

declare(strict_types=1);

namespace App\Links\Ui\Request;

final readonly class UpdateShortLinkRequest
{
    private function __construct(
        public string $name,
        public string $targetUrl,
        public int $version,
    ) {
    }

    public static function fromJson(string $body): self
    {
        $decoded = CreateShortLinkRequest::decode($body);
        $version = $decoded['version'] ?? null;
        if (!\is_int($version) || $version < 1) {
            throw new \InvalidArgumentException('version_invalid');
        }

        return new self(
            CreateShortLinkRequest::normalizeName($decoded['name'] ?? null),
            CreateShortLinkRequest::normalizeTargetUrl($decoded['targetUrl'] ?? null),
            $version,
        );
    }
}
