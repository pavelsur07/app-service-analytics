<?php

declare(strict_types=1);

namespace App\Links\Application;

use App\Links\Domain\ShortLink;

final readonly class ShortLinkMutationResult
{
    public function __construct(
        public ShortLinkMutationOutcome $outcome,
        public ?ShortLink $link,
    ) {
    }
}
