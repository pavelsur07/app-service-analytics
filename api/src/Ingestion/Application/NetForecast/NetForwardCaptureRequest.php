<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

final readonly class NetForwardCaptureRequest
{
    public function __construct(public string $requestId, public string $planId, public string $from, public string $to, public string $groupBy = 'day')
    {
        foreach ([$requestId, $planId] as $id) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
                throw new \InvalidArgumentException('Capture requires canonical UUIDs.');
            }
        }
        if ('7' !== $requestId[14] || !\in_array($groupBy, ['day', 'week', 'month', 'period'], true)) {
            throw new \InvalidArgumentException('Invalid capture request.');
        }
        foreach ([$from, $to] as $date) {
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (false === $at || $at->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException('Invalid capture date.');
            }
        }
        if ($from > $to || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days >= 184) {
            throw new \InvalidArgumentException('Capture range must contain 1..184 days.');
        }
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(['policy' => 'plan_forward_request_v1', 'kind' => 'input', 'planId' => $this->planId, 'from' => $this->from, 'to' => $this->to, 'groupBy' => $this->groupBy], \JSON_THROW_ON_ERROR));
    }
}
