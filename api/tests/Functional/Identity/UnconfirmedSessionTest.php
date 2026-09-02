<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Infrastructure\Repository\DoctrineUserRepository;
use App\Tests\Support\Builder\UserBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class UnconfirmedSessionTest extends WebTestCase
{
    public function testRestoredSessionOfUnconfirmedUserIsRejected(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()
            ->withEmail('unconfirmed-session@example.test')
            ->unconfirmed()
            ->persistWith(new DoctrineUserRepository($this->entityManager()));
        $client->loginUser($user, 'api');

        $client->request('GET', '/api/auth/me');

        self::assertResponseStatusCodeSame(401);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
