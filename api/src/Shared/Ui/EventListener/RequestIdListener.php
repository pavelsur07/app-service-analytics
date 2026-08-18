<?php

declare(strict_types=1);

namespace App\Shared\Ui\EventListener;

use App\Shared\Ui\RequestAttributes;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Идентификатор запроса — то, по чему строки журнала одного обращения
 * собираются вместе (CLAUDE.md, «Наблюдаемость»).
 *
 * Генерируется здесь, а не берётся из заголовка `X-Request-Id`. Заголовок
 * пришёл бы с машины клиента: расширение живёт среди чужого кода, и
 * значение оттуда — данные, которым нельзя верить. Своя генерация стоит
 * одной строки и не создаёт границы доверия там, где её можно не создавать.
 * Появится доверенный прокси, проставляющий заголовок, — решение
 * пересматривается.
 *
 * Восемь байт, не UUID: идентификатор нужен только чтобы отличать
 * запросы друг от друга в пределах разбора одного обращения, и короткий
 * читается глазами.
 */
final class RequestIdListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        // Приоритет выше нуля: атрибут должен стоять раньше, чем
        // что-либо начнёт писать в журнал в этом же запросе.
        return [KernelEvents::REQUEST => ['onKernelRequest', 512]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Подзапросы наследуют идентификатор основного: они часть того же
        // обращения, и второй идентификатор развалил бы разбор надвое.
        if (!$event->isMainRequest()) {
            return;
        }

        $event->getRequest()->attributes->set(
            RequestAttributes::RequestId,
            bin2hex(random_bytes(8)),
        );
    }
}
