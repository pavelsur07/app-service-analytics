<?php

declare(strict_types=1);

namespace App\Identity\Ui\EventListener;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdminMfaRepository;
use App\Identity\Ui\Security\AdminMfaSession;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/** ROLE_ADMIN сама по себе не даёт привилегий до MFA, в том числе старой сессии. */
final readonly class AdminMfaGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Security $security,
        private AdminMfaRepository $mfa,
        private AdminMfaSession $session,
        private TokenStorageInterface $tokens,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $path = rawurldecode($request->getPathInfo());
        if (!str_starts_with($path, '/api/') || '/api/admin/ping' === $path) {
            return;
        }
        $administrator = $this->security->getUser();
        if (!$administrator instanceof Administrator) {
            return;
        }
        $state = $this->session->state($request, $administrator->id(), $this->mfa->state($administrator->id()));
        if ('authenticated' === $state) {
            return;
        }
        $route = $request->attributes->get('_route');
        if ('expired' !== $state && \in_array($route, [
            'identity_admin_mfa_status', 'identity_admin_mfa_enroll',
            'identity_admin_mfa_confirm', 'identity_admin_mfa_verify',
        ], true)) {
            return;
        }
        // Pending login остаётся только для разрешённых MFA endpoints.
        // Прочие запросы не получают полномочий, но не расходуют challenge.
        if ('expired' === $state) {
            $request->getSession()->invalidate();
            $this->tokens->setToken(null);
        }
        $event->setResponse(new JsonResponse(new ValidationErrorResponse(401, 'admin_mfa_required', 'Подтвердите вход вторым фактором.'), 401));
    }
}
