<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Ui\Security;

use App\Identity\Domain\CaptchaUnavailable;
use App\Identity\Domain\CaptchaUnavailableReason;
use App\Identity\Domain\CaptchaVerification;
use App\Identity\Ui\Security\PublicFormProtection;
use App\Identity\Ui\Security\PublicFormProtectionDecision;
use App\Tests\Support\Fake\FakeCaptchaVerifier;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

final class PublicFormProtectionTest extends TestCase
{
    private const string EMAIL = 'owner@example.com';
    private const string CLIENT_IP = '203.0.113.8';
    private const string CAPTCHA_TOKEN = 'captcha-token';
    private const string KERNEL_SECRET = 'test-kernel-secret';

    public function testAllowsRegistrationAfterConsumingBothHashedLimitsAndPassingCaptcha(): void
    {
        $events = new FakeLimiterEvents();
        $emailLimiter = new FakeLimiterFactory('email', $this->acceptedLimit(), $events);
        $ipLimiter = new FakeLimiterFactory('ip', $this->acceptedLimit(), $events);
        $captcha = new FakeCaptchaVerifier();
        $handler = new TestHandler();

        $result = $this->protection($emailLimiter, $ipLimiter, $captcha, $handler)->check(self::EMAIL, self::CLIENT_IP, self::CAPTCHA_TOKEN);

        self::assertSame(PublicFormProtectionDecision::Allowed, $result->decision);
        self::assertNull($result->retryAfter);
        self::assertSame([hash_hmac('sha256', 'email:owner@example.com', self::KERNEL_SECRET)], $emailLimiter->keys);
        self::assertSame([hash_hmac('sha256', 'ip:203.0.113.8', self::KERNEL_SECRET)], $ipLimiter->keys);
        self::assertSame(['email.consume', 'ip.consume'], $events->entries);
        self::assertSame(1, $captcha->calls);
        self::assertSame(self::CAPTCHA_TOKEN, $captcha->receivedToken);
        self::assertSame(self::CLIENT_IP, $captcha->receivedClientIp);
        self::assertSame([], $handler->getRecords());
    }

    public function testReturnsTheLaterRetryAfterAfterConsumingBothRejectedLimitsWithoutCallingCaptcha(): void
    {
        $events = new FakeLimiterEvents();
        $emailRetryAfter = new \DateTimeImmutable('2026-09-02T12:20:00+00:00');
        $ipRetryAfter = new \DateTimeImmutable('2026-09-02T12:40:00+00:00');
        $emailLimiter = new FakeLimiterFactory('email', $this->rejectedLimit($emailRetryAfter), $events);
        $ipLimiter = new FakeLimiterFactory('ip', $this->rejectedLimit($ipRetryAfter), $events);
        $captcha = new FakeCaptchaVerifier();

        $result = $this->protection($emailLimiter, $ipLimiter, $captcha, new TestHandler())->check(self::EMAIL, self::CLIENT_IP, self::CAPTCHA_TOKEN);

        self::assertSame(PublicFormProtectionDecision::RateLimited, $result->decision);
        self::assertSame($ipRetryAfter, $result->retryAfter);
        self::assertSame(['email.consume', 'ip.consume'], $events->entries);
        self::assertSame(0, $captcha->calls);
    }

    public function testRejectingOnlyTheEmailLimitStillConsumesTheIpLimitWithoutCallingCaptcha(): void
    {
        $events = new FakeLimiterEvents();
        $emailRetryAfter = new \DateTimeImmutable('2026-09-02T12:20:00+00:00');
        $emailLimiter = new FakeLimiterFactory('email', $this->rejectedLimit($emailRetryAfter), $events);
        $ipLimiter = new FakeLimiterFactory('ip', $this->acceptedLimit(), $events);
        $captcha = new FakeCaptchaVerifier();

        $result = $this->protection($emailLimiter, $ipLimiter, $captcha, new TestHandler())->check(self::EMAIL, self::CLIENT_IP, self::CAPTCHA_TOKEN);

        self::assertSame(PublicFormProtectionDecision::RateLimited, $result->decision);
        self::assertSame($emailRetryAfter, $result->retryAfter);
        self::assertSame(['email.consume', 'ip.consume'], $events->entries);
        self::assertSame(0, $captcha->calls);
    }

    public function testRejectingOnlyTheIpLimitStillConsumesTheEmailLimitWithoutCallingCaptcha(): void
    {
        $events = new FakeLimiterEvents();
        $ipRetryAfter = new \DateTimeImmutable('2026-09-02T12:40:00+00:00');
        $emailLimiter = new FakeLimiterFactory('email', $this->acceptedLimit(), $events);
        $ipLimiter = new FakeLimiterFactory('ip', $this->rejectedLimit($ipRetryAfter), $events);
        $captcha = new FakeCaptchaVerifier();

        $result = $this->protection($emailLimiter, $ipLimiter, $captcha, new TestHandler())->check(self::EMAIL, self::CLIENT_IP, self::CAPTCHA_TOKEN);

        self::assertSame(PublicFormProtectionDecision::RateLimited, $result->decision);
        self::assertSame($ipRetryAfter, $result->retryAfter);
        self::assertSame(['email.consume', 'ip.consume'], $events->entries);
        self::assertSame(0, $captcha->calls);
    }

    public function testReturnsCaptchaRejectedAfterAcceptedLimits(): void
    {
        $events = new FakeLimiterEvents();
        $emailLimiter = new FakeLimiterFactory('email', $this->acceptedLimit(), $events);
        $ipLimiter = new FakeLimiterFactory('ip', $this->acceptedLimit(), $events);
        $captcha = new FakeCaptchaVerifier(CaptchaVerification::Rejected);

        $result = $this->protection($emailLimiter, $ipLimiter, $captcha, new TestHandler())->check(self::EMAIL, self::CLIENT_IP, self::CAPTCHA_TOKEN);

        self::assertSame(PublicFormProtectionDecision::CaptchaRejected, $result->decision);
        self::assertNull($result->retryAfter);
        self::assertSame(['email.consume', 'ip.consume'], $events->entries);
        self::assertSame(1, $captcha->calls);
    }

    /**
     * @return iterable<string, array{bool, bool, list<string>}>
     */
    public static function rejectedLimits(): iterable
    {
        yield 'email only' => [false, true, ['email']];
        yield 'ip only' => [true, false, ['ip']];
        yield 'both' => [false, false, ['email', 'ip']];
    }

    /**
     * @param list<string> $expectedLimits
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedLimits')]
    public function testRateLimitLogsASanitizedWarningNamingFormAndLimits(bool $emailAccepted, bool $ipAccepted, array $expectedLimits): void
    {
        $events = new FakeLimiterEvents();
        $retryAfter = new \DateTimeImmutable('2026-09-02T12:20:00+00:00');
        $emailLimiter = new FakeLimiterFactory('email', $emailAccepted ? $this->acceptedLimit() : $this->rejectedLimit($retryAfter), $events);
        $ipLimiter = new FakeLimiterFactory('ip', $ipAccepted ? $this->acceptedLimit() : $this->rejectedLimit($retryAfter), $events);
        $handler = new TestHandler();

        $this->protection($emailLimiter, $ipLimiter, new FakeCaptchaVerifier(), $handler)->check(self::EMAIL, self::CLIENT_IP, self::CAPTCHA_TOKEN);

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('WARNING', $records[0]->level->getName());
        self::assertSame('Public form rate limit exceeded', $records[0]->message);
        self::assertSame(['form' => 'registration', 'limits' => $expectedLimits], $records[0]->context);
        $this->assertNoPersonalData($records[0]->message, $records[0]->context);
    }

    public function testCaptchaRejectionLogsASanitizedWarning(): void
    {
        $events = new FakeLimiterEvents();
        $handler = new TestHandler();

        $this->protection(
            new FakeLimiterFactory('email', $this->acceptedLimit(), $events),
            new FakeLimiterFactory('ip', $this->acceptedLimit(), $events),
            new FakeCaptchaVerifier(CaptchaVerification::Rejected),
            $handler,
        )->check(self::EMAIL, self::CLIENT_IP, self::CAPTCHA_TOKEN);

        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('WARNING', $records[0]->level->getName());
        self::assertSame('Public form captcha rejected', $records[0]->message);
        self::assertSame(['form' => 'registration'], $records[0]->context);
        $this->assertNoPersonalData($records[0]->message, $records[0]->context);
    }

    /**
     * @param array<mixed> $context
     */
    private function assertNoPersonalData(string $message, array $context): void
    {
        $record = json_encode(['message' => $message, 'context' => $context], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::EMAIL, $record);
        self::assertStringNotContainsString(self::CLIENT_IP, $record);
        self::assertStringNotContainsString(self::CAPTCHA_TOKEN, $record);
    }

    public function testReturnsUnavailableAndLogsOnlyASanitizedWarningWhenClientIpIsMissing(): void
    {
        $events = new FakeLimiterEvents();
        $emailLimiter = new FakeLimiterFactory('email', $this->acceptedLimit(), $events);
        $ipLimiter = new FakeLimiterFactory('ip', $this->acceptedLimit(), $events);
        $captcha = new FakeCaptchaVerifier();
        $handler = new TestHandler();

        $result = $this->protection($emailLimiter, $ipLimiter, $captcha, $handler)->check(self::EMAIL, null, self::CAPTCHA_TOKEN);

        self::assertSame(PublicFormProtectionDecision::Unavailable, $result->decision);
        self::assertNull($result->retryAfter);
        self::assertSame([], $events->entries);
        self::assertSame(0, $captcha->calls);
        $records = $handler->getRecords();
        self::assertCount(1, $records);
        self::assertSame('Public form protection unavailable', $records[0]->message);
        self::assertSame(['reason' => 'client_ip_missing'], $records[0]->context);
        $record = json_encode(['message' => $records[0]->message, 'context' => $records[0]->context], \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::EMAIL, $record);
        self::assertStringNotContainsString(self::CAPTCHA_TOKEN, $record);
    }

    public function testConvertsAlreadyLoggedCaptchaUnavailabilityWithoutANewWarning(): void
    {
        $events = new FakeLimiterEvents();
        $emailLimiter = new FakeLimiterFactory('email', $this->acceptedLimit(), $events);
        $ipLimiter = new FakeLimiterFactory('ip', $this->acceptedLimit(), $events);
        $captcha = new FakeCaptchaVerifier(unavailable: new CaptchaUnavailable(CaptchaUnavailableReason::Transport));
        $handler = new TestHandler();

        $result = $this->protection($emailLimiter, $ipLimiter, $captcha, $handler)->check(self::EMAIL, self::CLIENT_IP, self::CAPTCHA_TOKEN);

        self::assertSame(PublicFormProtectionDecision::Unavailable, $result->decision);
        self::assertNull($result->retryAfter);
        self::assertSame(['email.consume', 'ip.consume'], $events->entries);
        self::assertSame(1, $captcha->calls);
        self::assertSame([], $handler->getRecords());
    }

    private function protection(
        RateLimiterFactoryInterface $emailLimiter,
        RateLimiterFactoryInterface $ipLimiter,
        FakeCaptchaVerifier $captcha,
        TestHandler $handler,
    ): PublicFormProtection {
        return new PublicFormProtection(
            $emailLimiter,
            $ipLimiter,
            $captcha,
            new Logger('test', [$handler]),
            self::KERNEL_SECRET,
            'registration',
        );
    }

    private function acceptedLimit(): RateLimit
    {
        return new RateLimit(1, new \DateTimeImmutable('2026-09-02T12:00:00+00:00'), true, 5);
    }

    private function rejectedLimit(\DateTimeImmutable $retryAfter): RateLimit
    {
        return new RateLimit(0, $retryAfter, false, 5);
    }
}

final class FakeLimiterFactory implements RateLimiterFactoryInterface
{
    /** @var list<string|null> */
    public array $keys = [];

    public function __construct(
        private readonly string $name,
        private readonly RateLimit $limit,
        private readonly FakeLimiterEvents $events,
    ) {
    }

    public function create(?string $key = null): LimiterInterface
    {
        $this->keys[] = $key;

        return new FakeLimiter($this->name, $this->limit, $this->events);
    }
}

final class FakeLimiter implements LimiterInterface
{
    public function __construct(
        private readonly string $name,
        private readonly RateLimit $limit,
        private readonly FakeLimiterEvents $events,
    ) {
    }

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        throw new \LogicException('PublicFormProtection must consume limits immediately.');
    }

    public function consume(int $tokens = 1): RateLimit
    {
        $this->events->entries[] = $this->name.'.consume';

        return $this->limit;
    }

    public function reset(): void
    {
    }
}

final class FakeLimiterEvents
{
    /** @var list<string> */
    public array $entries = [];
}
