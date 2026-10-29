<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

use App\Ingestion\Domain\ValueObject\NetForecastQuantities;

/** Explicit output-frame allow-list, with no caller-selected class construction. */
final class NetQualitySealedValues
{
    /** @return array<string, mixed> */
    public static function fields(mixed $value, string $type): array
    {
        if (!\is_array($value) || ($value['_type'] ?? null) !== $type || !\is_array($value['fields'] ?? null)) {
            throw new \UnexpectedValueException('Unexpected sealed output type.');
        }
        NetQualitySchema::keys($value, ['_type', 'fields']);

        return NetQualitySchema::rows([$value['fields']], 1)[0];
    }

    public static function quantity(mixed $value): ?NetForecastQuantities
    {
        if (null === $value) {
            return null;
        }
        $fields = self::fields($value, NetForecastQuantities::class);
        NetQualitySchema::keys($fields, ['currentD', 'eligibleOpenQuantity', 'futureReturnsNumerator', 'futureReturnsDenominator', 'retainedOpenNumerator', 'retainedOpenDenominator', 'expectedNetNumerator', 'expectedNetDenominator']);
        $quantity = NetForecastQuantities::fromComponents(NetQualitySchema::string($fields, 'currentD', 16384), NetQualitySchema::string($fields, 'eligibleOpenQuantity', 16384), NetQualitySchema::string($fields, 'futureReturnsNumerator', 16384), NetQualitySchema::string($fields, 'futureReturnsDenominator', 16384), NetQualitySchema::string($fields, 'retainedOpenNumerator', 16384), NetQualitySchema::string($fields, 'retainedOpenDenominator', 16384));
        $codec = new NetForwardManifestCodec();
        if ($codec->encode(get_object_vars($quantity)) !== $codec->encode($fields)) {
            throw new \UnexpectedValueException('Sealed quantity fields contradict their exact components.');
        }

        return $quantity;
    }
}
