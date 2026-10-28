<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Config;

use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;

/** Trusted deployment evidence; an empty default never invents source coverage. */
final readonly class NetForecastCaptureEpochs
{
    public function __construct(private string $epochsJson = '[]')
    {
    }

    public function at(\DateTimeImmutable $at): ?NetForecastCaptureEpoch
    {
        try {
            $records = json_decode($this->epochsJson, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Invalid capture epoch JSON.', previous: $error);
        }
        // Distinguish {} from [], which associative decoding would conflate.
        if (!\is_array($records) || !array_is_list($records) || '[' !== substr(ltrim($this->epochsJson), 0, 1) || \count($records) > 16) {
            throw new \InvalidArgumentException('Capture epoch config must be a bounded list.');
        }
        $epochs = [];
        foreach ($records as $record) {
            if (!\is_array($record) || [] !== array_diff(array_keys($record), ['epochId', 'captureVersion', 'startUtc', 'endUtc', 'cutoverRelease'])
                || !\is_string($record['epochId'] ?? null) || !\is_string($record['captureVersion'] ?? null)
                || !\is_string($record['startUtc'] ?? null) || !\array_key_exists('endUtc', $record)
                || (null !== $record['endUtc'] && !\is_string($record['endUtc'])) || !\is_string($record['cutoverRelease'] ?? null)) {
                throw new \InvalidArgumentException('Invalid capture epoch fields.');
            }
            $epochs[] = new NetForecastCaptureEpoch($record['epochId'], $record['captureVersion'], self::utc($record['startUtc']), null === $record['endUtc'] ? null : self::utc($record['endUtc']), $record['cutoverRelease']);
        }
        usort($epochs, static fn (NetForecastCaptureEpoch $a, NetForecastCaptureEpoch $b): int => $a->start <=> $b->start);
        $previous = null;
        $ids = [];
        $selected = null;
        foreach ($epochs as $epoch) {
            if (isset($ids[$epoch->id]) || (null !== $previous && (null === $previous->end || $epoch->start < $previous->end))) {
                throw new \InvalidArgumentException('Capture epochs overlap or repeat an identity.');
            }
            $ids[$epoch->id] = true;
            if ($epoch->contains($at)) {
                $selected = $epoch;
            }
            $previous = $epoch;
        }

        return $selected;
    }

    private static function utc(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new \InvalidArgumentException('Capture timestamps must be exact UTC Z seconds.');
        }

        return $date;
    }
}
