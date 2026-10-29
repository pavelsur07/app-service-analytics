<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetQualityExact;

/** Scope sums use exact source prices; rounding happens once per completed case. */
final readonly class NetQualityCaseScorer
{
    /** @return array<string, mixed> */
    public function score(NetQualityObservation $observation, NetQualityTruthOutcome $truth): array
    {
        $model = NetQualityExact::fraction('0');
        $simple = NetQualityExact::fraction('0');
        $modelMoney = NetQualityExact::fraction('0');
        $simpleMoney = NetQualityExact::fraction('0');
        $truthMoney = NetQualityExact::fraction('0');
        $currency = null;
        $moneyReason = null;
        $methodReasons = [];
        $simpleReasons = [];
        foreach (NetQualitySchema::rows($observation->payload['parts'] ?? null, 128) as $part) {
            $id = NetQualitySchema::string($part, 'sourceRowId');
            $raw = NetQualityExact::fraction((string) NetQualitySchema::integer($part, 'rawQuantity'));
            $a = $this->quantity($part['model'] ?? null, $raw);
            $b = $this->quantity($part['simple'] ?? null, $raw);
            $model = null === $model || null === $a ? null : $model->plus($a);
            $simple = null === $simple || null === $b ? null : $simple->plus($b);
            if (null === $a) {
                $methodReasons[] = NetQualitySchema::nullableString($part['modelReason'] ?? null) ?? 'method_unavailable';
            }
            if (null === $b) {
                $simpleReasons[] = NetQualitySchema::nullableString($part['simpleReason'] ?? null) ?? 'simple_unavailable';
            }
            $price = $part['unitPriceMinor'] ?? null;
            $code = $part['currency'] ?? null;
            if (!\is_string($price) || 1 !== preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D', $price) || !\is_string($code) || 1 !== preg_match('/^[A-Z]{3}$/D', $code)) {
                $moneyReason = 'source_price_or_currency_unavailable';
                continue;
            }
            if (null !== $currency && $currency !== $code) {
                $moneyReason = 'mixed_currency_scope';
                continue;
            }
            $currency = $code;
            $unit = NetQualityExact::fraction($price);
            if (null !== $a) {
                $modelMoney = $modelMoney->plus($a->times($unit));
            }
            if (null !== $b) {
                $simpleMoney = $simpleMoney->plus($b->times($unit));
            }
            if (isset($truth->quantities['s:'.$id])) {
                $truthMoney = $truthMoney->plus(NetQualityExact::fraction((string) $truth->quantities['s:'.$id])->times($unit));
            }
        }
        if (null === $model || null === $simple || 'ready' !== $truth->state) {
            $moneyReason ??= 'paired_quantity_unavailable';
        }
        foreach ([$modelMoney, $simpleMoney, $truthMoney] as $amount) {
            $rounded = $amount->roundedMinor();
            if ($rounded->compare(NetQualityExact::fraction((string) \PHP_INT_MIN)) < 0 || $rounded->compare(NetQualityExact::fraction((string) \PHP_INT_MAX)) > 0) {
                $moneyReason = 'money_minor_overflow';
            }
        }

        return ['caseId' => $observation->caseKey(), 'orderCluster' => hash('sha256', $observation->companyId.'|'.$observation->accountId.'|'.$observation->orderNumber), 'group' => $observation->group, 'originReadAtUtc' => $observation->readAtUtc, 'input' => $observation->inputRef->toArray(), 'components' => $observation->components, 'selectionRowHash' => hash('sha256', (new NetForwardManifestCodec())->encode($observation->toArray())), 'truth' => $truth->truthRef?->toArray(), 'truthState' => $truth->state, 'truthQuantity' => $truth->quantity?->toArray(), 'truthReasons' => $truth->reasons, 'truthInvalidations' => $truth->invalidations, 'censored' => $truth->censored, 'method' => $model?->toArray(), 'simple' => $simple?->toArray(), 'methodReasons' => array_values(array_unique($methodReasons)), 'simpleReasons' => array_values(array_unique($simpleReasons)), 'currency' => null === $moneyReason ? $currency : null, 'methodMoney' => null === $moneyReason ? $modelMoney->toArray() : null, 'simpleMoney' => null === $moneyReason ? $simpleMoney->toArray() : null, 'truthMoney' => null === $moneyReason ? $truthMoney->toArray() : null, 'moneyReason' => $moneyReason, 'legacyTarget' => 'target_not_comparable'];
    }

    private function quantity(mixed $value, NetQualityExact $raw): ?NetQualityExact
    {
        if (null === $value) {
            return null;
        }
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('Expected exact prediction.');
        }
        $number = NetQualityExact::fromArray($value);
        if ($number->isNegative() || $number->compare($raw) > 0) {
            throw new \UnexpectedValueException('Prediction exceeds selected ordered units.');
        }

        return $number;
    }
}
