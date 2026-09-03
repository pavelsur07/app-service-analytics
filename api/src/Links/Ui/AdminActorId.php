<?php

declare(strict_types=1);

namespace App\Links\Ui;

use App\Identity\Application\Facade\IdentityAdminFacade;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class AdminActorId
{
    public function __construct(
        private Security $security,
        private IdentityAdminFacade $identity,
    ) {
    }

    public function __invoke(): string
    {
        $user = $this->security->getUser();
        if (null === $user) {
            throw new \LogicException('ROLE_ADMIN route has no authenticated user.');
        }

        return $this->identity->administratorId($user->getUserIdentifier());
    }
}
