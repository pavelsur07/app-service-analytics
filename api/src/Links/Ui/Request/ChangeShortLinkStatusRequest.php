<?php

declare(strict_types=1);

namespace App\Links\Ui\Request;

use App\Links\Domain\ShortLinkStatus;

final readonly class ChangeShortLinkStatusRequest
{
    private function __construct(
        public ShortLinkStatus $status,
        public int $version,
    ) {
    }

    public static function fromJson(string $body): self
    {
        $decoded = CreateShortLinkRequest::decode($body);
        $rawStatus = $decoded['status'] ?? null;
        $status = \is_string($rawStatus) ? ShortLinkStatus::tryFrom($rawStatus) : null;
        if (null === $status) {
            throw new \InvalidArgumentException('status_invalid');
        }

        $version = $decoded['version'] ?? null;
        if (!\is_int($version) || $version < 1) {
            throw new \InvalidArgumentException('version_invalid');
        }

        return new self($status, $version);
    }
}
