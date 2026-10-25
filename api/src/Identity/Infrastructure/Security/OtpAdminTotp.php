<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\AdminTotp;
use OTPHP\TOTP;

final class OtpAdminTotp implements AdminTotp
{
    public function generateSecret(): string
    {
        return TOTP::generate()->getSecret();
    }

    public function provisioningUri(string $secret, string $email): string
    {
        if ('' === $secret || '' === $email) {
            throw new \InvalidArgumentException('A non-empty MFA secret and label are required.');
        }

        return TOTP::createFromSecret($secret)->withLabel($email)->withIssuer('Conwix')->getProvisioningUri();
    }

    public function matchTimeStep(string $secret, string $code, int $timestamp): ?int
    {
        if ('' === $secret || 1 !== preg_match('/\A[0-9]{6}\z/', $code) || $timestamp < 0) {
            return null;
        }
        $totp = TOTP::createFromSecret($secret);
        foreach ([0, -1, 1] as $offset) {
            $at = $timestamp + $offset * 30;
            if ($at >= 0 && hash_equals($totp->at($at), $code)) {
                return intdiv($at, 30);
            }
        }

        return null;
    }
}
