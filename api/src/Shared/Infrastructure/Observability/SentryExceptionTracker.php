<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Observability;

use App\Shared\Domain\ExceptionTracker;
use Sentry\State\HubInterface;

final readonly class SentryExceptionTracker implements ExceptionTracker
{
    public function __construct(private HubInterface $hub)
    {
    }

    public function capture(\Throwable $failure): void
    {
        $this->hub->captureException($failure);
    }
}
