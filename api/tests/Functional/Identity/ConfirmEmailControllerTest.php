<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\EmailVerificationSecret;
use App\Identity\Infrastructure\Repository\DoctrineCompanyMemberRepository;
use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\CompanyBuilder;
use App\Tests\Support\Builder\CompanyMemberBuilder;
use App\Tests\Support\Builder\EmailVerificationTokenBuilder;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ConfirmEmailControllerTest extends WebTestCase
{
    public function testValidTokenConfirmsAndOpensSessionThenCannotBeUsedAgain(): void
    {
        $client = static::createClient();
        $secret = EmailVerificationSecret::generate();
        $user = $this->accountWithToken($secret, new \DateTimeImmutable('-1 hour'));

        $this->confirm($client, $secret->plainText());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['outcome' => 'confirmed', 'next' => '/onboarding'], $this->decode($client));

        $client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame($user->email(), $this->decode($client)['email']);

        $this->confirm($client, $secret->plainText());
        self::assertResponseStatusCodeSame(409);
        self::assertSame(['outcome' => 'already_consumed', 'next' => null], $this->decode($client));
    }

    public function testExpiredAndUnknownTokensHaveGoneResponse(): void
    {
        $client = static::createClient();
        $expired = EmailVerificationSecret::generate();
        $this->accountWithToken($expired, new \DateTimeImmutable('-25 hours'));

        $this->confirm($client, $expired->plainText());
        self::assertResponseStatusCodeSame(410);
        self::assertSame(['outcome' => 'expired', 'next' => null], $this->decode($client));

        $this->confirm($client, EmailVerificationSecret::generate()->plainText());
        self::assertResponseStatusCodeSame(410);
        self::assertSame(['outcome' => 'expired', 'next' => null], $this->decode($client));
    }

    public function testBlankTokenIsRejected(): void
    {
        $client = static::createClient();

        $this->confirm($client, '');

        self::assertResponseStatusCodeSame(422);
    }

    private function accountWithToken(EmailVerificationSecret $secret, \DateTimeImmutable $issuedAt): User
    {
        /** @var CompanyRepository $companies */
        $companies = self::getContainer()->get(CompanyRepository::class);
        $users = new DoctrineUserRepository($this->entityManager());
        $members = new DoctrineCompanyMemberRepository($this->entityManager());
        $company = CompanyBuilder::aCompany()
            ->withName('HTTP confirmation '.Uuid::v7()->toRfc4122())
            ->persistWith($companies);
        $user = UserBuilder::aUser()
            ->withEmail(\sprintf('http-confirmation-%s@example.test', Uuid::v7()->toRfc4122()))
            ->unconfirmed()
            ->persistWith($users);
        CompanyMemberBuilder::aCompanyMember()
            ->withCompany($company)
            ->withUser($user)
            ->persistWith($companies, $users, $members);
        EmailVerificationTokenBuilder::aToken($user, $secret->hash())
            ->withIssuedAt($issuedAt)
            ->persistWith($this->entityManager());

        return $user;
    }

    private function confirm(KernelBrowser $client, string $token): void
    {
        $client->request(
            'POST',
            '/api/auth/email-verification/confirm',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['token' => $token], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(KernelBrowser $client): array
    {
        $content = $client->getResponse()->getContent();
        self::assertIsString($content);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);

        return $payload;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
