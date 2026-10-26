<?php

declare(strict_types=1);

namespace App\Identity\Ui\Request;

use OpenApi\Attributes as OA;

final readonly class AdminMfaCodeRequest
{
    public function __construct(
        #[OA\Property(pattern: '^(?:[0-9]{6}|[0-9a-f]{32})$')]
        public string $code,
    ) {
    }

    public static function fromJson(string $json): self
    {
        try {
            $value = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('mfa_code_invalid');
        }
        if (!\is_array($value) || !\is_string($value['code'] ?? null)) {
            throw new \InvalidArgumentException('mfa_code_invalid');
        }
        $code = strtolower(trim($value['code']));
        if (1 !== preg_match('/\A(?:[0-9]{6}|[0-9a-f]{32})\z/', $code)) {
            throw new \InvalidArgumentException('mfa_code_invalid');
        }

        return new self($code);
    }
}
