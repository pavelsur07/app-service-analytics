<?php

declare(strict_types=1);

namespace App\Links\Infrastructure\Persistence;

use App\Links\Domain\ShortLinkClick;
use App\Links\Domain\ShortLinkClickRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Один append-only DBAL INSERT на публичный переход (ADR-022).
 */
final readonly class DoctrineShortLinkClickWriter implements ShortLinkClickRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function record(ShortLinkClick $click): void
    {
        $this->connection->insert(
            'short_link_click',
            [
                'id' => $click->id()->toRfc4122(),
                'short_link_id' => $click->shortLinkId()->toRfc4122(),
                'clicked_at' => $click->clickedAt()->format('Y-m-d H:i:s'),
                'user_agent' => $click->userAgent(),
                'referer' => $click->referer(),
                'is_bot' => $click->isBot(),
            ],
            ['is_bot' => ParameterType::BOOLEAN],
        );
    }
}
