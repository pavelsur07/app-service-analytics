<?php

declare(strict_types=1);

namespace App\Tests\Functional\Links;

use App\Identity\Domain\Administrator;
use App\Identity\Infrastructure\Repository\DoctrineAdministratorRepository;
use App\Tests\Support\Builder\AdministratorBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class AdminLinksControllerTest extends WebTestCase
{
    public function testUnauthenticatedListIsRejected(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/admin/links');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMonthlyEndpointRejectsFutureMonth(): void
    {
        $client = static::createClient();
        $this->loginAdmin($client);
        $future = (new \DateTimeImmutable('first day of next month', new \DateTimeZone('UTC')))->format('Y-m');
        $client->request('GET', '/api/admin/links/'.Uuid::v7()->toRfc4122()."/clicks?month={$future}");
        self::assertResponseStatusCodeSame(422);
        self::assertSame('month_in_future', $this->decode($client)['code']);
    }

    private function loginAdmin(KernelBrowser $client): Administrator
    {
        $administrator = AdministratorBuilder::aBootstrapSuperAdmin()
            ->withEmail('admin-links@conwix.local')
            ->persistWith(new DoctrineAdministratorRepository($this->entityManager()));
        $client->loginUser($administrator, 'admin');

        return $administrator;
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
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
