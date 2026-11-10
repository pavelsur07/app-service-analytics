<?php

declare(strict_types=1);

namespace App\Advertising\Ui\Request;

use App\Advertising\Application\PolicyInput;
use App\Shared\Domain\ValueObject\Money;

final class PolicyRequest
{
    /** @return array<string, mixed> */
    public static function body(string $json): array
    {
        try {
            $value = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('invalid_request');
        }
        if (!\is_array($value) || array_is_list($value) || \strlen($json) > 8192) {
            throw new \InvalidArgumentException('invalid_request');
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \InvalidArgumentException('invalid_request');
            } $result[$key] = $item;
        }

        return $result;
    }

    /** @param array<string, mixed> $body */
    public static function input(array $body, bool $create): PolicyInput
    {
        $allowed = ['dailyLimit', 'start', 'stop', 'pollSeconds', 'enabled', ...($create ? ['accountId', 'campaignId'] : ['version'])];
        if ([] !== array_diff(array_keys($body), $allowed) || !\is_array($body['dailyLimit'] ?? null) || !\is_int($body['dailyLimit']['amountMinor'] ?? null) || 'RUB' !== ($body['dailyLimit']['currency'] ?? null) || [] !== array_diff(array_keys($body['dailyLimit']), ['amountMinor', 'currency']) || !\is_string($body['start'] ?? null) || !\is_string($body['stop'] ?? null) || !\is_int($body['pollSeconds'] ?? null) || !\is_bool($body['enabled'] ?? null)) {
            throw new \InvalidArgumentException('invalid_request');
        }

        return new PolicyInput(Money::ofMinor($body['dailyLimit']['amountMinor'], $body['dailyLimit']['currency']), $body['start'], $body['stop'], $body['pollSeconds'], $body['enabled']);
    }
}
