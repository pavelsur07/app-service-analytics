<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Domain\AdminMfaState;
use App\Identity\Ui\Security\AdminMfaSession;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Uid\Uuid;

final class AdminMfaSessionTest extends TestCase
{
    public function testChallengeExpiresAtFiveMinutesAndBindsAdministratorAndVersion(): void
    {
        $clock = new MutableMfaSessionClock();
        $session = new AdminMfaSession($clock);
        $request = Request::create('/api/admin/auth/mfa/status');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $id = Uuid::v7();
        $session->start($request, $id, 0);
        self::assertSame('enrollment_required', $session->state($request, $id, new AdminMfaState(0, false)));
        self::assertSame('expired', $session->state($request, Uuid::v7(), new AdminMfaState(0, false)));
        self::assertSame('expired', $session->state($request, $id, new AdminMfaState(1, false)));
        $session->enrollmentStarted($request, 1);
        $clock->advance(299);
        self::assertSame('enrollment_required', $session->state($request, $id, new AdminMfaState(1, false)));
        $clock->advance(1);
        self::assertSame('expired', $session->state($request, $id, new AdminMfaState(1, false)));
    }

    public function testVerifiedSessionSurvivesChallengeTimeoutButNotFactorChangeOrFreshLogin(): void
    {
        $clock = new MutableMfaSessionClock();
        $session = new AdminMfaSession($clock);
        $request = Request::create('/api/admin/auth/mfa/status');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $id = Uuid::v7();
        $session->start($request, $id, 1);
        self::assertSame('verification_required', $session->state($request, $id, new AdminMfaState(1, true)));
        $oldId = $request->getSession()->getId();
        $session->verified($request, $id, 1);
        self::assertNotSame($oldId, $request->getSession()->getId());
        $clock->advance(600);
        self::assertSame('authenticated', $session->state($request, $id, new AdminMfaState(1, true)));
        self::assertSame('expired', $session->state($request, $id, new AdminMfaState(2, true)));
        self::assertSame('expired', $session->state($request, $id, new AdminMfaState(1, false)));
        $session->start($request, $id, 1);
        self::assertSame('verification_required', $session->state($request, $id, new AdminMfaState(1, true)));
    }
}

final class MutableMfaSessionClock implements ClockInterface
{
    private \DateTimeImmutable $at;

    public function __construct()
    {
        $this->at = new \DateTimeImmutable('2026-10-25T12:00:00+00:00');
    }

    public function now(): \DateTimeImmutable
    {
        return $this->at;
    }

    public function advance(int $seconds): void
    {
        $this->at = $this->at->modify(\sprintf('+%d seconds', $seconds));
    }
}
