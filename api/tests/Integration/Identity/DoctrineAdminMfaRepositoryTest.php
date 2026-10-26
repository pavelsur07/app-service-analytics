<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\AdminMfaEncryption;
use App\Identity\Domain\AdminMfaRepository;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use App\Tests\Support\Builder\AdministratorMfaBuilder;
use App\Tests\Support\Builder\AdministratorMfaRecoveryCodeBuilder;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineAdminMfaRepositoryTest extends KernelTestCase
{
    public function testEnrollmentReplayRecoveryAndRegenerationAreAtomicAndScopedToAdministrator(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $administrators = new DoctrineAdministratorRepository($em);
        $first = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('first@example.test')->persistWith($administrators);
        $second = AdministratorBuilder::anAdministrator()->withEmail('second@example.test')->createdBy($first)->persistWith($administrators);
        $repo = self::getContainer()->get(AdminMfaRepository::class);
        self::assertInstanceOf(AdminMfaRepository::class, $repo);
        $timestamp = 1792929600;
        $now = new \DateTimeImmutable('@'.$timestamp);
        $enrollment = $repo->beginEnrollment($first->id(), 0, $now);
        $encryption = self::getContainer()->get(AdminMfaEncryption::class);
        self::assertInstanceOf(AdminMfaEncryption::class, $encryption);
        AdministratorMfaBuilder::anEnrollment($second, $encryption->encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'))->withCreatedAt($now)->persistWith($em);
        $other = $repo->beginEnrollment($second->id(), 1, $now);
        $totp = TOTP::createFromSecret($enrollment->secret);
        $confirmed = $repo->confirmEnrollment($first->id(), $enrollment->version, $totp->at($timestamp), $now);
        self::assertNotNull($confirmed);
        self::assertCount(10, $confirmed->recoveryCodes);
        self::assertSame(10, \count(array_unique($confirmed->recoveryCodes)));
        self::assertTrue($repo->state($first->id())->enabled);
        self::assertNull($repo->verify($first->id(), $enrollment->version, $totp->at($timestamp), $now));
        $next = $now->modify('+30 seconds');
        self::assertNotNull($repo->verify($first->id(), $enrollment->version, $totp->at($timestamp + 30), $next));
        self::assertNull($repo->verify($first->id(), $enrollment->version, $totp->at($timestamp + 30), $next));

        $otherConfirmed = $repo->confirmEnrollment($second->id(), $other->version, TOTP::createFromSecret($other->secret)->at($timestamp), $now);
        self::assertNotNull($otherConfirmed);
        $fixtureCode = '00112233445566778899aabbccddeeff';
        AdministratorMfaRecoveryCodeBuilder::aRecoveryCode($second, hash('sha256', $fixtureCode))->persistWith($em);
        self::assertNull($repo->verify($first->id(), $enrollment->version, $fixtureCode, $now));
        self::assertNotNull($repo->verify($second->id(), $other->version, $fixtureCode, $now));
        self::assertNull($repo->verify($second->id(), $other->version, $confirmed->recoveryCodes[0], $now));
        self::assertNotNull($repo->verify($first->id(), $enrollment->version, $confirmed->recoveryCodes[0], $now));
        self::assertNull($repo->verify($first->id(), $enrollment->version, $confirmed->recoveryCodes[0], $now));

        $later = $now->modify('+60 seconds');
        $regenerated = $repo->regenerateRecoveryCodes($first->id(), $enrollment->version, $totp->at($timestamp + 60), $later);
        self::assertNotNull($regenerated);
        self::assertSame($enrollment->version + 1, $regenerated->version);
        self::assertNull($repo->verify($first->id(), $regenerated->version, $confirmed->recoveryCodes[1], $later));
        self::assertNotNull($repo->verify($first->id(), $regenerated->version, $regenerated->recoveryCodes[0], $later));
        $stored = $em->getConnection()->fetchOne('SELECT encrypted_secret FROM administrator_mfa WHERE administrator_id = :id', ['id' => $first->id()->toRfc4122()]);
        self::assertIsString($stored);
        self::assertStringNotContainsString($enrollment->secret, $stored);
        self::assertFalse($em->getConnection()->fetchOne('SELECT id FROM administrator_mfa_recovery_code WHERE code_hash = :plain', ['plain' => $regenerated->recoveryCodes[0]]));
    }

    public function testAuditFailureRollsBackEnrollmentAndIssuedRecoveryCodes(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $admin = AdministratorBuilder::aBootstrapSuperAdmin()->persistWith(new DoctrineAdministratorRepository($em));
        $repo = self::getContainer()->get(AdminMfaRepository::class);
        self::assertInstanceOf(AdminMfaRepository::class, $repo);
        $now = new \DateTimeImmutable('@1792929600');
        $enrollment = $repo->beginEnrollment($admin->id(), 0, $now);
        $em->getConnection()->executeStatement("ALTER TABLE audit_record ADD CONSTRAINT test_reject_mfa_audit CHECK (action <> 'administrator.mfa_enabled') NOT VALID");
        try {
            $repo->confirmEnrollment($admin->id(), 1, TOTP::createFromSecret($enrollment->secret)->at(1792929600), $now);
            self::fail('The audit constraint must reject enrollment.');
        } catch (DriverException) {
            self::assertFalse($repo->state($admin->id())->enabled);
            self::assertFalse($em->getConnection()->fetchOne('SELECT id FROM administrator_mfa_recovery_code WHERE administrator_id = :id LIMIT 1', ['id' => $admin->id()->toRfc4122()]));
        }
    }
}
