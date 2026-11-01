<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain;

final class CatalogSyncFailure extends \RuntimeException
{
    public function __construct(string $code, public readonly ?\DateTimeImmutable $retryAt = null)
    {
        parent::__construct($code);
    }
}
