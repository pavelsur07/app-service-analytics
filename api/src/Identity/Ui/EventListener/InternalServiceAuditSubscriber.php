<?php

declare(strict_types=1);

namespace App\Identity\Ui\EventListener;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Аудит каждого обращения к сервисному контуру (CLAUDE.md §1, ADR-043
 * п. 4) — с любым исходом, а не только с выдачей. Отказ токена (401)
 * отдают entry point и failure handler firewall-а, неверные параметры —
 * контроллер до чтения; ни до одного из них строка контроллера
 * не доходит, а попытка с чужим или почти совпадающим токеном — самое
 * нужное при расследовании событие.
 *
 * Поэтому одна точка на выходе: `kernel.response` видит ответ, кто бы
 * его ни собрал. В строке — метод, путь без строки запроса, статус
 * и адрес клиента; ни заголовков, ни тела.
 */
#[WithMonologChannel('service_audit')]
final readonly class InternalServiceAuditSubscriber implements EventSubscriberInterface
{
    private const string PREFIX = '/api/internal/';

    public function __construct(private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onKernelResponse'];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), self::PREFIX)) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();
        $context = [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $status,
            'client_ip' => $request->getClientIp(),
        ];

        if (\in_array($status, [Response::HTTP_UNAUTHORIZED, Response::HTTP_FORBIDDEN], true)) {
            $this->logger->warning('service contour request refused', $context);

            return;
        }

        $this->logger->info('service contour request', $context);
    }
}
