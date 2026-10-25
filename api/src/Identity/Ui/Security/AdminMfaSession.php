<?php

declare(strict_types=1);

namespace App\Identity\Ui\Security;

use App\Identity\Domain\AdminMfaState;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** Только серверная сессия; профиль и его версия каждый раз читаются из БД. */
final readonly class AdminMfaSession
{
    private const string CHALLENGE = 'admin_mfa_challenge';
    private const string VERIFIED = 'admin_mfa_verified';

    public function __construct(private ClockInterface $clock)
    {
    }

    public function start(Request $request, Uuid $administratorId, int $version): void
    {
        $session = $request->getSession();
        $session->remove(self::VERIFIED);
        $session->set(self::CHALLENGE, [
            'id' => $administratorId->toRfc4122(),
            'version' => $version,
            'expiresAt' => $this->clock->now()->getTimestamp() + 300,
        ]);
    }

    public function state(Request $request, Uuid $administratorId, AdminMfaState $state): string
    {
        $session = $request->getSession();
        $proof = $session->get(self::VERIFIED);
        if ($state->enabled && \is_array($proof) && ($proof['id'] ?? null) === $administratorId->toRfc4122() && ($proof['version'] ?? null) === $state->version) {
            return 'authenticated';
        }
        $challenge = $session->get(self::CHALLENGE);
        if (!\is_array($challenge) || ($challenge['id'] ?? null) !== $administratorId->toRfc4122() || ($challenge['version'] ?? null) !== $state->version || !\is_int($challenge['expiresAt'] ?? null) || $challenge['expiresAt'] <= $this->clock->now()->getTimestamp()) {
            return 'expired';
        }

        return $state->enabled ? 'verification_required' : 'enrollment_required';
    }

    public function enrollmentStarted(Request $request, int $version): void
    {
        $challenge = $request->getSession()->get(self::CHALLENGE);
        if (!\is_array($challenge)) {
            throw new \LogicException('MFA enrollment requires a password challenge.');
        }
        $challenge['version'] = $version;
        $request->getSession()->set(self::CHALLENGE, $challenge);
    }

    public function verified(Request $request, Uuid $administratorId, int $version): void
    {
        $session = $request->getSession();
        $session->migrate(true);
        $session->remove(self::CHALLENGE);
        $session->set(self::VERIFIED, ['id' => $administratorId->toRfc4122(), 'version' => $version]);
    }
}
