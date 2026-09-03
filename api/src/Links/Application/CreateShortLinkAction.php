<?php

declare(strict_types=1);

namespace App\Links\Application;

use App\Links\Domain\ShortCodeGenerator;
use App\Links\Domain\ShortLink;
use App\Links\Domain\ShortLinkRepository;
use Symfony\Component\Uid\Uuid;

final readonly class CreateShortLinkAction
{
    public function __construct(
        private ShortLinkRepository $links,
        private ShortCodeGenerator $codes,
    ) {
    }

    public function __invoke(string $name, string $targetUrl, string $actorAdminId): ShortLink
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $link = ShortLink::create(
                $this->codes->generate(),
                $name,
                $targetUrl,
                Uuid::fromString($actorAdminId),
                new \DateTimeImmutable(),
            );

            if ($this->links->tryAdd($link)) {
                return $link;
            }
        }

        throw new ShortCodeGenerationFailed();
    }
}
