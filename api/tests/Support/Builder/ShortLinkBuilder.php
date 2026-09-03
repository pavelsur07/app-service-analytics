<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Links\Domain\ShortLink;
use App\Links\Domain\ShortLinkRepository;
use App\Links\Domain\ShortLinkStatus;
use Symfony\Component\Uid\Uuid;

final class ShortLinkBuilder
{
    private string $code = 'AbC0123';
    private string $name = 'September campaign';
    private string $targetUrl = 'https://example.com/campaign';
    private ShortLinkStatus $status = ShortLinkStatus::Active;
    private Uuid $createdByAdminId;
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
        $this->createdByAdminId = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable('2026-09-03 09:00:00 UTC');
    }

    public static function aShortLink(): self
    {
        return new self();
    }

    public function withCode(string $code): self
    {
        $clone = clone $this;
        $clone->code = $code;

        return $clone;
    }

    public function withName(string $name): self
    {
        $clone = clone $this;
        $clone->name = $name;

        return $clone;
    }

    public function withTargetUrl(string $targetUrl): self
    {
        $clone = clone $this;
        $clone->targetUrl = $targetUrl;

        return $clone;
    }

    public function withStatus(ShortLinkStatus $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withCreatedByAdminId(Uuid $createdByAdminId): self
    {
        $clone = clone $this;
        $clone->createdByAdminId = $createdByAdminId;

        return $clone;
    }

    public function withCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $clone = clone $this;
        $clone->createdAt = $createdAt;

        return $clone;
    }

    public function build(): ShortLink
    {
        $link = ShortLink::create(
            $this->code,
            $this->name,
            $this->targetUrl,
            $this->createdByAdminId,
            $this->createdAt,
        );

        if (ShortLinkStatus::Active !== $this->status) {
            $link->changeStatus($this->status, $this->createdAt);
        }

        return $link;
    }

    public function persistWith(ShortLinkRepository $repository): ShortLink
    {
        $link = $this->build();
        if (!$repository->tryAdd($link)) {
            throw new \LogicException('Short link builder generated an occupied code.');
        }

        return $link;
    }
}
