<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdminMfaRepository;
use App\Identity\Ui\Security\AdminMfaSession;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;

/** Fixture полного входа для тестов ролей/данных; сам MFA проверяется через HTTP. */
trait AuthenticateAdministrator
{
    protected function authenticateAdministrator(KernelBrowser $client, Administrator $administrator): void
    {
        $repository = self::getContainer()->get(AdminMfaRepository::class);
        self::assertInstanceOf(AdminMfaRepository::class, $repository);
        $clock = self::getContainer()->get(ClockInterface::class);
        self::assertInstanceOf(ClockInterface::class, $clock);
        $state = $repository->state($administrator->id());
        if (!$state->enabled) {
            $enrollment = $repository->beginEnrollment($administrator->id(), $state->version, $clock->now());
            $timestamp = $clock->now()->getTimestamp();
            if ($timestamp < 0) {
                self::fail('A non-negative timestamp is required.');
            }
            self::assertNotNull($repository->confirmEnrollment($administrator->id(), $enrollment->version, TOTP::createFromSecret($enrollment->secret)->at($timestamp), $clock->now()));
            $state = $repository->state($administrator->id());
        }
        $client->loginUser($administrator, 'admin');
        $session = $client->getSession();
        self::assertNotNull($session);
        $cookie = $client->getCookieJar()->get($session->getName());
        $request = Request::create('/api/admin/auth/mfa/verify');
        $request->setSession($session);
        (new AdminMfaSession($clock))->verified($request, $administrator->id(), $state->version);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId(), domain: $cookie?->getDomain() ?? ''));
    }
}
