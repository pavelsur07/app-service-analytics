<?php

declare(strict_types=1);

namespace App\Links\Ui\Request;

final readonly class CreateShortLinkRequest
{
    public const int MAX_NAME_LENGTH = 120;
    public const int MAX_TARGET_URL_LENGTH = 2048;

    private function __construct(
        public string $name,
        public string $targetUrl,
    ) {
    }

    public static function fromJson(string $body): self
    {
        $decoded = self::decode($body);

        return new self(
            self::normalizeName($decoded['name'] ?? null),
            self::normalizeTargetUrl($decoded['targetUrl'] ?? null),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function decode(string $body): array
    {
        try {
            $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('malformed_json');
        }

        if (!\is_array($decoded)) {
            throw new \InvalidArgumentException('malformed_json');
        }

        return $decoded;
    }

    public static function normalizeName(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('name_invalid');
        }

        $name = trim($value);
        if ('' === $name || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('name_invalid');
        }

        return $name;
    }

    public static function normalizeTargetUrl(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('target_url_invalid');
        }

        $url = trim($value);
        if ('' === $url || mb_strlen($url) > self::MAX_TARGET_URL_LENGTH || false === filter_var($url, \FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('target_url_invalid');
        }

        $parts = parse_url($url);
        $scheme = \is_array($parts) && \is_string($parts['scheme'] ?? null)
            ? strtolower($parts['scheme'])
            : null;
        if (
            !\is_array($parts)
            || !\in_array($scheme, ['http', 'https'], true)
            || !\is_string($parts['host'] ?? null)
            || '' === $parts['host']
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new \InvalidArgumentException('target_url_invalid');
        }

        return $url;
    }
}
