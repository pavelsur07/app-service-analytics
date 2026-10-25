<?php

declare(strict_types=1);

namespace App\Identity\Ui\EventListener;

use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** CSRF-граница cookie-контуров; выполняется до json_login и logout firewall. */
final readonly class SessionRequestProtectionSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $sellerOrigins
     * @param list<string> $adminOrigins
     */
    public function __construct(
        #[Autowire(env: 'csv:SESSION_SELLER_ORIGINS')]
        private array $sellerOrigins,
        #[Autowire(env: 'csv:SESSION_ADMIN_ORIGINS')]
        private array $adminOrigins,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        // Нормализация совпадает с UrlMatcher/PathRequestMatcher Symfony.
        $path = rawurldecode($request->getPathInfo());
        if (!str_starts_with($path, '/api/') || str_starts_with($path, '/api/extension/') || str_starts_with($path, '/api/internal/')) {
            return;
        }
        // Symfony регистрирует logout для ANY: GET иначе изменял бы сессию
        // до контроллера и обходил защиту небезопасных HTTP-методов.
        if (\in_array($path, ['/api/auth/logout', '/api/admin/auth/logout'], true) && !$request->isMethod('POST')) {
            $response = $this->error(405, 'method_not_allowed', 'Logout requires POST.');
            $response->headers->set('Allow', 'POST');
            $event->setResponse($response);

            return;
        }
        if ($request->isMethodSafe() || $request->isMethod('OPTIONS')) {
            return;
        }

        $origins = str_starts_with($path, '/api/admin/') ? $this->adminOrigins : $this->sellerOrigins;
        $origin = $request->headers->get('Origin');
        if (null === $origin && $request->headers->has('Referer')) {
            $origin = $this->refererOrigin((string) $request->headers->get('Referer'));
        }
        if ('1' !== $request->headers->get('X-Conwix-Request') || (null !== $origin && !\in_array($origin, $origins, true))) {
            $event->setResponse($this->error(403, 'request_origin_invalid', 'Request protection header or origin is invalid.'));

            return;
        }
        $hasBody = '' !== $request->getContent() || $request->request->count() > 0 || $request->files->count() > 0 || (int) $request->headers->get('Content-Length', '0') > 0;
        if ($hasBody && 'application/json' !== strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]))) {
            $event->setResponse($this->error(415, 'unsupported_media_type', 'A non-empty request body requires application/json.'));
        }
    }

    private function refererOrigin(string $referer): string
    {
        $parts = parse_url($referer);
        if (false === $parts || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(new ValidationErrorResponse($status, $code, $message), $status);
    }
}
