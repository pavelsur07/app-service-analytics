<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Domain\ValueObject;

use App\ProductResearchOzon\Domain\MonthSlice;

final readonly class RunMonthState
{
    public function __construct(public MonthSlice $period, public string $status, public ?string $snapshotId, public ?int $rowCount, public ?string $errorCode)
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $period->dateFrom, new \DateTimeZone('UTC'));
        $fromErrors = \DateTimeImmutable::getLastErrors();
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $period->dateTo, new \DateTimeZone('UTC'));
        if (1 !== preg_match('/^20[0-9]{2}-(0[1-9]|1[0-2])$/D', $period->month) || false === $from || false === $to || false !== $fromErrors || false !== \DateTimeImmutable::getLastErrors()
            || $from->format('Y-m-01') !== $period->dateFrom || $from->format('Y-m') !== $period->month || $to->format('Y-m') !== $period->month || $to < $from
            || 1 + (int) $from->diff($to)->days !== $period->days || $period->partial !== ($to->format('d') !== $to->format('t'))
            || !\in_array($status, ['queued', 'running', 'ready', 'error'], true) || (null !== $rowCount && $rowCount < 0)
            || (null !== $snapshotId && !RunProgress::validId($snapshotId))
            || !\in_array($errorCode, [null, 'contract_violation', 'credentials_rejected', 'retry_exhausted', 'incomplete_snapshot', 'deadline_exceeded'], true)
            || ('ready' === $status && (null === $snapshotId || null === $rowCount || null !== $errorCode))
            || ('error' === $status) !== (null !== $errorCode)) {
            throw new \InvalidArgumentException('invalid_run_progress');
        }
    }

    /** @return array{month: string, date_from: string, date_to: string, days: int, partial: bool} */
    public function periodPayload(): array
    {
        return ['month' => $this->period->month, 'date_from' => $this->period->dateFrom, 'date_to' => $this->period->dateTo, 'days' => $this->period->days, 'partial' => $this->period->partial];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['period' => $this->periodPayload(), 'status' => $this->status, 'snapshot_id' => $this->snapshotId, 'row_count' => $this->rowCount, 'error_code' => $this->errorCode];
    }
}
