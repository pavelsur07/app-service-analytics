<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastCaptureEpoch;
use App\Ingestion\Infrastructure\Query\NetForecast as Input;
use App\Shared\Domain\ValueObject\Money;

/** Explicit constructor allow-list: no unserialize, autoloaded caller classes or property injection. */
final readonly class NetForwardInputCodec
{
    private const array TYPES = [
        NetTrainingBatch::class,
        Input\NetTrainingContext::class,
        NetForecastCaptureEpoch::class,
        Input\GrossTrainingAggregateRow::class,
        Input\ReturnTrainingAggregateRow::class,
        Input\ReturnTrainingAgeRow::class,
        Input\NetTrainingBudgetFailure::class,
        Input\NetTargetBatch::class,
        Input\NetTargetSkuRow::class,
        Input\NetTargetDayRow::class,
        Input\NetTargetGroupRow::class,
        Input\NetTargetPartRow::class,
        Input\NetTargetSourceTrace::class,
        Input\NetTargetReturnTrace::class,
        Input\NetTargetReadinessRow::class,
        Input\NetTargetRequest::class,
        \App\Ingestion\Domain\ValueObject\NetForwardTruth::class,
        \App\Ingestion\Domain\ValueObject\NetForwardTruthResult::class,
    ];

    public function pack(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new \UnexpectedValueException('Input nesting limit exceeded.');
        }
        if ($value instanceof \DateTimeImmutable) {
            return ['_type' => 'utc', 'value' => $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')];
        }
        if ($value instanceof \App\Ingestion\Domain\ValueObject\NetForecastFutureT1) {
            return ['_type' => 'future_t1', 'numerator' => $value->numerator(), 'denominator' => $value->denominator(), 'reason' => $value->unavailableReason];
        }
        if ($value instanceof Money) {
            return ['_type' => 'money', 'minor' => (string) $value->minorAmount(), 'currency' => $value->currency()];
        }
        if (\is_object($value)) {
            $fields = [];
            foreach (get_object_vars($value) as $name => $field) {
                $fields[$name] = $this->pack($field, $depth + 1);
            }

            return ['_type' => $value::class, 'fields' => $fields];
        }
        if (\is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->pack($item, $depth + 1), $value);
        }
        if (null === $value || \is_string($value) || \is_int($value) || \is_bool($value)) {
            return $value;
        }
        throw new \UnexpectedValueException('Unsupported input scalar.');
    }

    public function unpack(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new \UnexpectedValueException('Input nesting limit exceeded.');
        }
        if (!\is_array($value)) {
            if (null === $value || \is_string($value) || \is_int($value) || \is_bool($value)) {
                return $value;
            }
            throw new \UnexpectedValueException('Unsupported input scalar.');
        }
        if (!isset($value['_type'])) {
            return array_map(fn (mixed $item): mixed => $this->unpack($item, $depth + 1), $value);
        }
        if ('utc' === $value['_type']) {
            if (array_keys($value) !== ['_type', 'value'] || !\is_string($value['value'])) {
                throw new \UnexpectedValueException('Invalid timestamp input.');
            }
            $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value['value'], new \DateTimeZone('UTC'));
            if (false === $at || $at->format('Y-m-d\TH:i:s.u\Z') !== $value['value']) {
                throw new \UnexpectedValueException('Invalid timestamp input.');
            }

            return $at;
        }
        if (!\is_string($value['_type']) || !\in_array($value['_type'], self::TYPES, true) || array_keys($value) !== ['_type', 'fields'] || !\is_array($value['fields'])) {
            throw new \UnexpectedValueException('Unknown input type or fields.');
        }
        $class = $value['_type'];
        $constructor = (new \ReflectionClass($class))->getConstructor() ?? throw new \LogicException('Input DTO constructor missing.');
        $expected = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $constructor->getParameters());
        $actual = array_keys($value['fields']);
        sort($expected);
        sort($actual);
        if ($actual !== $expected) {
            throw new \UnexpectedValueException('Input DTO fields mismatch.');
        }
        $fields = [];
        foreach ($value['fields'] as $name => $field) {
            $fields[$name] = $this->unpack($field, $depth + 1);
        }

        return (new \ReflectionClass($class))->newInstanceArgs($fields);
    }
}
