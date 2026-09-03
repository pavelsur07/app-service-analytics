<?php

declare(strict_types=1);

namespace App\Links\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Неизменяемый факт перехода по короткой ссылке (ADR-022).
 *
 * Пишется DBAL, а Entity существует для проверки схемы и Builder-тестов.
 * IP намеренно отсутствует; сырые заголовки ограничиваются до создания.
 */
#[ORM\Entity]
#[ORM\Table(name: 'short_link_click')]
#[ORM\Index(name: 'idx_short_link_click_link_time', columns: ['short_link_id', 'clicked_at'])]
class ShortLinkClick
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $shortLinkId;

    #[ORM\Column]
    private readonly \DateTimeImmutable $clickedAt;

    #[ORM\Column(length: 1024, nullable: true)]
    private readonly ?string $userAgent;

    #[ORM\Column(length: 2048, nullable: true)]
    private readonly ?string $referer;

    #[ORM\Column]
    private readonly bool $isBot;

    private function __construct(
        Uuid $id,
        Uuid $shortLinkId,
        \DateTimeImmutable $clickedAt,
        ?string $userAgent,
        ?string $referer,
        bool $isBot,
    ) {
        $this->id = $id;
        $this->shortLinkId = $shortLinkId;
        $this->clickedAt = $clickedAt;
        $this->userAgent = $userAgent;
        $this->referer = $referer;
        $this->isBot = $isBot;
    }

    public static function record(
        Uuid $shortLinkId,
        \DateTimeImmutable $clickedAt,
        ?string $userAgent,
        ?string $referer,
        bool $isBot,
    ): self {
        return new self(Uuid::v7(), $shortLinkId, $clickedAt, $userAgent, $referer, $isBot);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function shortLinkId(): Uuid
    {
        return $this->shortLinkId;
    }

    public function clickedAt(): \DateTimeImmutable
    {
        return $this->clickedAt;
    }

    public function userAgent(): ?string
    {
        return $this->userAgent;
    }

    public function referer(): ?string
    {
        return $this->referer;
    }

    public function isBot(): bool
    {
        return $this->isBot;
    }
}
