<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\AuditAction;
use App\Identity\Domain\ValueObject\AdminRole;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Identity\Ui\Request\CreateAdministratorRequest;
use App\Tests\Support\Builder\AdministratorBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `SuperAdmin` заводит `Admin` (ADR-017). Через HTTP, потому что предмет
 * проверки — разделение прав между двумя ролями, а живёт оно
 * в `#[IsGranted]` и `role_hierarchy`; вызвать их иначе нечем
 * (CLAUDE.md §9).
 *
 * Отказ `Admin`'у проверяется тестом, а не только интерфейсом: спрятанная
 * кнопка защитой не является, и узнать о дыре здесь мы должны раньше
 * клиента.
 */
final class CreateAdministratorControllerTest extends WebTestCase
{
    public function testSuperAdminCreatesAdminAndItIsWrittenToTheJournal(): void
    {
        $client = static::createClient();
        $administrators = $this->administrators();

        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('boss@conwix.local')->persistWith($administrators);
        $client->loginUser($boss, 'admin');

        $this->post($client, ['email' => 'New.Ops@Conwix.Local', 'password' => 'long-enough-password']);

        self::assertResponseStatusCodeSame(201);
        $payload = $this->decode($client);
        self::assertSame('new.ops@conwix.local', $payload['email'], 'email нормализуется, как и у продавца');
        self::assertSame('admin', $payload['role'], 'форма заводит только нижнюю роль');
        self::assertArrayNotHasKey('password', $payload);
        self::assertArrayNotHasKey('passwordHash', $payload);

        $created = $administrators->findByEmail('new.ops@conwix.local');
        self::assertNotNull($created);
        self::assertSame(AdminRole::Admin, $created->role());
        self::assertNotNull($created->createdByAdminId());
        self::assertSame((string) $boss->id(), (string) $created->createdByAdminId(), 'автор — тот, кто завёл');

        $record = $this->entityManager()->getConnection()->fetchAssociative(
            'SELECT company_id, actor_user_id, actor_admin_id, action, subject_id, new_value FROM audit_record WHERE action = :action',
            ['action' => AuditAction::AdministratorCreated],
        );
        self::assertIsArray($record, 'создание Admin обязано попасть в журнал (ADR-017)');
        self::assertNull($record['company_id'], 'событие системного контура к компании не относится');
        self::assertNull($record['actor_user_id']);
        self::assertSame((string) $boss->id(), $record['actor_admin_id']);
        self::assertSame((string) $created->id(), $record['subject_id']);
        self::assertSame('new.ops@conwix.local', $record['new_value']);
    }

    public function testAdminIsDeniedAndNothingIsCreated(): void
    {
        $client = static::createClient();
        $administrators = $this->administrators();

        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('boss2@conwix.local')->persistWith($administrators);
        $admin = AdministratorBuilder::anAdministrator()->withEmail('ops@conwix.local')->createdBy($boss)->persistWith($administrators);
        $client->loginUser($admin, 'admin');

        $this->post($client, ['email' => 'sneaky@conwix.local', 'password' => 'long-enough-password']);

        self::assertResponseStatusCodeSame(403, 'нижняя роль не заводит администраторов');
        self::assertNull($administrators->findByEmail('sneaky@conwix.local'));
    }

    public function testSellerSessionIsDenied(): void
    {
        $client = static::createClient();

        $this->post($client, ['email' => 'x@conwix.local', 'password' => 'long-enough-password']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testDuplicateEmailIsRejectedOnInsert(): void
    {
        $client = static::createClient();
        $administrators = $this->administrators();

        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('boss3@conwix.local')->persistWith($administrators);
        $client->loginUser($boss, 'admin');

        $this->post($client, ['email' => 'twice@conwix.local', 'password' => 'long-enough-password']);
        self::assertResponseStatusCodeSame(201);

        $this->post($client, ['email' => 'twice@conwix.local', 'password' => 'another-long-password']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('administrator_email_taken', $this->decode($client)['code']);
    }

    public function testShortPasswordAndBadEmailAreRejected(): void
    {
        $client = static::createClient();
        $administrators = $this->administrators();

        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('boss4@conwix.local')->persistWith($administrators);
        $client->loginUser($boss, 'admin');

        $short = str_repeat('a', CreateAdministratorRequest::MIN_PASSWORD_LENGTH - 1);
        $this->post($client, ['email' => 'ok@conwix.local', 'password' => $short]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('password_too_short', $this->decode($client)['code']);

        $this->post($client, ['email' => 'не-адрес', 'password' => 'long-enough-password']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('email_invalid', $this->decode($client)['code']);

        self::assertNull($administrators->findByEmail('ok@conwix.local'));
    }

    public function testRoleInBodyIsIgnoredAndCannotEscalate(): void
    {
        $client = static::createClient();
        $administrators = $this->administrators();

        $boss = AdministratorBuilder::aBootstrapSuperAdmin()->withEmail('boss5@conwix.local')->persistWith($administrators);
        $client->loginUser($boss, 'admin');

        // Поля роли в контракте нет; если оно однажды появится
        // по недосмотру, этот тест покажет это раньше клиента.
        $this->post($client, [
            'email' => 'escalate@conwix.local',
            'password' => 'long-enough-password',
            'role' => 'super_admin',
        ]);

        self::assertResponseStatusCodeSame(201);
        $created = $administrators->findByEmail('escalate@conwix.local');
        self::assertNotNull($created);
        self::assertSame(AdminRole::Admin, $created->role(), 'роль из тела запроса не должна ничего менять');
    }

    /**
     * @param array<string, string> $body
     */
    private function post(KernelBrowser $client, array $body): void
    {
        $client->request(
            'POST',
            '/api/admin/administrators',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, \JSON_THROW_ON_ERROR),
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

    private function administrators(): DoctrineAdministratorRepository
    {
        return new DoctrineAdministratorRepository($this->entityManager());
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
