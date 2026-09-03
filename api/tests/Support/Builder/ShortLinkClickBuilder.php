<?php

declare(strict_types=1);

namespace App\Tests\Support\Builder;

use App\Links\Domain\ShortLink;
use App\Links\Domain\ShortLinkClick;
use App\Links\Domain\ShortLinkClickRepository;
use Symfony\Component\Uid\Uuid;

final class ShortLinkClickBuilder
{
    private Uuid $shortLinkId;
    private \DateTimeImmutable $clickedAt;
    private ?string $userAgent = 'Mozilla/5.0 Chrome/130.0 Safari/537.36';
    private ?string $referer = 'https://example.com/newsletter';
    private bool $isBot = false;

    private function __construct()
    {
        $this->shortLinkId = Uuid::v7();
        $this->clickedAt = new \DateTimeImmutable('2026-09-03 10:00:00 UTC');
    }

    public static function aClick(): self
    {
        return new self();
    }

    public function forLink(ShortLink $link): self
    {
        return $this->withShortLinkId($link->id());
    }

    public function withShortLinkId(Uuid $shortLinkId): self
    {
        $clone = clone $this;
        $clone->shortLinkId = $shortLinkId;

        return $clone;
    }

    public function withClickedAt(\DateTimeImmutable $clickedAt): self
    {
        $clone = clone $this;
        $clone->clickedAt = $clickedAt;

        return $clone;
    }

    public function withUserAgent(?string $userAgent): self
    {
        $clone = clone $this;
        $clone->userAgent = $userAgent;

        return $clone;
    }

    public function withReferer(?string $referer): self
    {
        $clone = clone $this;
        $clone->referer = $referer;

        return $clone;
    }

    public function asBot(bool $isBot = true): self
    {
        $clone = clone $this;
        $clone->isBot = $isBot;

        return $clone;
    }

    public function build(): ShortLinkClick
    {
        return ShortLinkClick::record(
            $this->shortLinkId,
            $this->clickedAt,
            $this->userAgent,
            $this->referer,
            $this->isBot,
        );
    }

    public function persistWith(ShortLinkClickRepository $repository): ShortLinkClick
    {
        $click = $this->build();
        $repository->record($click);

        return $click;
    }
}
