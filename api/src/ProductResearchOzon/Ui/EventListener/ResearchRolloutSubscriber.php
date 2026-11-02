<?php

declare(strict_types=1);

namespace App\ProductResearchOzon\Ui\EventListener;

use App\ProductResearchOzon\Infrastructure\Config\ResearchRollout;
use App\ProductResearchOzon\Ui\Controller\ResearchController;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class ResearchRolloutSubscriber implements EventSubscriberInterface
{
    public function __construct(private ResearchRollout $rollout)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Auth/membership subscribers run first; never replace their denial controller.
        return [KernelEvents::CONTROLLER => ['onController', -64]];
    }

    public function onController(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $handler = \is_array($controller) ? $controller[0] : $controller;
        if (!$handler instanceof ResearchController) {
            return;
        }
        $company = $event->getRequest()->attributes->get('companyId');
        if (\is_string($company) && $this->rollout->allows($company)) {
            return;
        }
        $event->setController(static fn (): JsonResponse => new JsonResponse(new ValidationErrorResponse(404, 'feature_disabled', 'Подбор товаров Ozon пока недоступен.'), 404, ['Cache-Control' => 'no-store']));
    }
}
