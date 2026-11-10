<?php

declare(strict_types=1);

namespace App\Advertising\Ui\Controller\Internal;

use App\Advertising\Application\ObservationInput;
use App\Advertising\Infrastructure\Repository\ObservationProjection;
use App\Advertising\Ui\Request\PolicyRequest;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/internal/ozon-ads/observations', methods: ['POST'])]
#[IsGranted('ROLE_INTERNAL_SERVICE')]
final readonly class ObservationProjectionController
{
    public function __construct(private bool $adsEnabled, private ObservationProjection $projection, private ClockInterface $clock)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->adsEnabled) {
            return new JsonResponse(['code' => 'feature_disabled'], 404);
        }
        try {
            $input = ObservationInput::fromArray(PolicyRequest::body($request->getContent()), $this->clock->now());
            $this->projection->accept($input->companyId, $input);

            return new JsonResponse(['schema_version' => '1', 'accepted' => true]);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['code' => 'invalid_observation'], 422);
        }
    }
}
