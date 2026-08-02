<?php

declare(strict_types=1);

namespace App\Shared\Ui\Controller;

use App\Shared\Ui\Response\AppInfoResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/seller/ping', name: 'seller_ping', methods: ['GET'])]
final class SellerPingController
{
    public function __construct(
        #[Autowire(param: 'app.version')]
        private readonly string $version,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(new AppInfoResponse(
            app: 'conwix-seller-api',
            version: $this->version,
            respondedAt: (new \DateTimeImmutable())->format(DATE_ATOM),
        ));
    }
}
