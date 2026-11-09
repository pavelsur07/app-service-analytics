<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Application\Message\CheckOzonAdSkuReportMessage;
use App\Ingestion\Application\Message\OrderOzonAdSkuReportMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Наблюдение не управляет retries, не вызывает API и не заменяет исключения. */
final readonly class OzonAdReportDiagnostics
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.ozon_report')]
        private LoggerInterface $logger,
        private string $kernelSecret,
    ) {
    }

    public function trace(OrderOzonAdSkuReportMessage|CheckOzonAdSkuReportMessage $message): OzonAdReportTrace
    {
        return new OzonAdReportTrace($message, $this->kernelSecret);
    }

    /**
     * @param 'token'|'order'|'check'|'download' $operation
     * @param \Closure(): string                 $request
     */
    public function request(OzonAdReportTrace $trace, string $operation, \Closure $request): string
    {
        $started = hrtime(true);
        $this->write($trace, ['event' => 'request_started', 'operation' => $operation]);
        try {
            $body = $request();
        } catch (\Throwable $failure) {
            $elapsed = intdiv(hrtime(true) - $started, 1_000_000);
            $details = OzonAdReportFailureDetails::describe($failure, time(), $trace->companyId(), $this->kernelSecret);
            $this->write($trace, ['event' => 'request_failed', 'operation' => $operation, 'duration_ms' => $elapsed] + $details);

            throw $failure;
        }
        $this->write($trace, ['event' => 'request_returned', 'operation' => $operation, 'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000)]);

        return $body;
    }

    /**
     * @param 'accepted'|'state'|'stored'|'check_scheduled'|'retry_scheduled'|'skipped'|'abandoned'|'handler_failed'|'processing_failed' $event
     */
    public function event(OzonAdReportTrace $trace, string $event, ?string $reportUuid = null, ?string $state = null, ?int $delayMs = null): void
    {
        $fields = ['event' => $event];
        if (null !== $reportUuid) {
            $fields['report_uuid'] = OzonAdReportTrace::uuid($reportUuid);
        }
        if (null !== $state) {
            $fields['state'] = \in_array($state, ['NOT_STARTED', 'IN_PROGRESS', 'OK', 'ERROR'], true) ? $state : 'unknown';
        }
        if (null !== $delayMs) {
            $fields['delay_ms'] = $delayMs;
        }
        $this->write($trace, $fields);
    }

    /** @param array<string, int|string|null> $fields */
    private function write(OzonAdReportTrace $trace, array $fields): void
    {
        try {
            $this->logger->info('ozon_report_diagnostic', $fields + $trace->context());
        } catch (\Throwable) {
            // Журнал недоступен — прежнее поведение загрузки сохраняется.
        }
    }
}
