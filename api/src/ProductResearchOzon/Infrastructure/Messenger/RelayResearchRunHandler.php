<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Messenger;

use App\ProductResearchOzon\Application\Message\DeliverResearchRun;
use App\ProductResearchOzon\Application\RelayResearchRunAction;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RelayResearchRunHandler
{
    public function __construct(private RelayResearchRunAction $relay)
    {
    }

    public function __invoke(DeliverResearchRun $message): void
    {
        ($this->relay)($message->companyId, $message->runId);
    }
}
