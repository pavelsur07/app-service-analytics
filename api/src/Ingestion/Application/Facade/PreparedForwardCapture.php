<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Facade;

/** Internal single-use publication handle; no query, Entity, key or source bytes in its public API. */
final readonly class PreparedForwardCapture
{
    /** @param \Closure(): ForwardArtifactRef $publish */
    public function __construct(private string $companyId, private string $accountId, private \Closure $publish)
    {
    }

    public function publish(string $companyId, string $accountId): ForwardArtifactRef
    {
        if ($companyId !== $this->companyId || $accountId !== $this->accountId) {
            throw new \InvalidArgumentException('Prepared capture scope mismatch.');
        }

        return ($this->publish)();
    }
}
