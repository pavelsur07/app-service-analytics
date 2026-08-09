<?php

declare(strict_types=1);

namespace App\Identity\Ui\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Умолчание Symfony — 302-редирект на "/": не подходит для JSON API,
 * фронтендовый apiPost() получил бы HTML вместо тела для response.json().
 * В Symfony 7.4 firewall.logout лишился опции success_handler — актуальный
 * способ переопределить ответ выхода — подписка на LogoutEvent.
 */
#[AsEventListener]
final class ApiLogoutResponseListener
{
    public function __invoke(LogoutEvent $event): void
    {
        $event->setResponse(new JsonResponse([]));
    }
}
