<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\AdminMfaRepository;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use Doctrine\ORM\EntityManagerInterface;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetAdminMfaCommandTest extends KernelTestCase
{
    public function testResetRequiresExplicitApplyAndRecordsVerifiedOperatorWithoutSecrets(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('operator@example.test')->persistWith(new DoctrineAdministratorRepository($em));
        $repository = self::getContainer()->get(AdminMfaRepository::class);
        self::assertInstanceOf(AdminMfaRepository::class, $repository);
        $now = new \DateTimeImmutable('@1792929600');
        $enrollment = $repository->beginEnrollment($boss->id(), 0, $now);
        self::assertNotNull($repository->confirmEnrollment($boss->id(), $enrollment->version, TOTP::createFromSecret($enrollment->secret)->at(1792929600), $now));
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:identity:reset-admin-mfa'));
        $input = ['email' => $boss->email(), '--operator' => $boss->email(), '--identity-reference' => 'verified-identity-123'];
        self::assertSame(Command::SUCCESS, $tester->execute($input));
        self::assertTrue($repository->state($boss->id())->enabled, 'Без apply ничего не меняется.');
        self::assertSame(Command::SUCCESS, $tester->execute([...$input, '--apply' => true]));
        self::assertFalse($repository->state($boss->id())->enabled);
        self::assertSame(2, $repository->state($boss->id())->version);
        $row = $em->getConnection()->fetchAssociative('SELECT actor_admin_id, new_value FROM audit_record WHERE action = :action', ['action' => 'administrator.mfa_reset']);
        self::assertIsArray($row);
        self::assertSame($boss->id()->toRfc4122(), $row['actor_admin_id']);
        self::assertSame('verified-identity-123', $row['new_value']);
        self::assertStringNotContainsString($enrollment->secret, $tester->getDisplay());
    }

    public function testLowerRoleAndMissingIdentityReferenceCannotResetMfa(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $administrators = new DoctrineAdministratorRepository($em);
        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('boss@example.test')->persistWith($administrators);
        $lower = AdministratorBuilder::anAdministrator()->createdBy($boss)->withEmail('lower@example.test')->persistWith($administrators);
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:identity:reset-admin-mfa'));
        self::assertSame(Command::FAILURE, $tester->execute(['email' => $boss->email(), '--operator' => $lower->email(), '--identity-reference' => 'verified-123', '--apply' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['email' => $boss->email(), '--operator' => $boss->email(), '--apply' => true]));
        self::assertFalse($em->getConnection()->fetchOne("SELECT id FROM audit_record WHERE action = 'administrator.mfa_reset' LIMIT 1"));
    }
}
