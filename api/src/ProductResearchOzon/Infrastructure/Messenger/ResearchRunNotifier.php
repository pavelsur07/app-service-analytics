<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Infrastructure\Messenger;

use App\ProductResearchOzon\Application\Message\DeliverResearchRun;
use App\ProductResearchOzon\Domain\RunNotifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

final readonly class ResearchRunNotifier implements RunNotifier
{
    public function __construct(private MessageBusInterface $bus, private LoggerInterface $logger)
    {
    }

    public function notify(string $companyId, string $runId): void
    {
        try {
            $this->bus->dispatch(new DeliverResearchRun($companyId, $runId), [new TransportNamesStamp(['async_ingestion'])]);
        } catch (\Throwable $failure) {
            // Intent is already committed; company-scoped repair command will deliver it.
            try {
                $this->logger->warning('research_run_notification_failed', ['company_id' => $companyId, 'run_id' => $runId, 'error_class' => $failure::class]);
            } catch (\Throwable) { /* fail-soft telemetry */
            }
        }
    }
}
