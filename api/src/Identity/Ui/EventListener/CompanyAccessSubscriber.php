<?php

declare(strict_types=1);

namespace App\Identity\Ui\EventListener;

use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\User;
use App\Shared\Ui\RequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Единая точка проверки доступа к company-scoped маршрутам (ТЗ §6,
 * CLAUDE.md §1). Срабатывает на любом маршруте с параметром companyId
 * в атрибутах запроса — не только на sales-facts, но и на всех будущих
 * company-scoped маршрутах в любом модуле, без правки Ingestion (или
 * кого-либо ещё) и без Deptrac-нарушения: имя параметра — соглашение,
 * не импорт чужого модуля.
 *
 * 401 (не аутентифицирован) отдаёт security.access_control раньше, чем
 * запрос доходит до kernel.controller (ApiAuthenticationEntryPoint) —
 * здесь User уже есть всегда.
 */
final class CompanyAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly CompanyMemberRepository $companyMembers,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $companyId = $event->getRequest()->attributes->get('companyId');
        if (!\is_string($companyId)) {
            return;
        }

        $user = $this->security->getUser();
        \assert($user instanceof User);

        if ($this->companyMembers->existsForUserAndCompany($companyId, $user->id()->toRfc4122())) {
            // Кто действует — сюда же, рядом с проверкой членства:
            // аудит-журнал (ADR-007) требует автора у каждой записи,
            // а контроллеры чужих модулей класс User импортировать
            // не могут (зависимости строго вниз).
            $event->getRequest()->attributes->set(RequestAttributes::ActorUserId, $user->id()->toRfc4122());

            return;
        }

        // Тело без данных компании (ТЗ, критерий приёмки 3) — заменяем
        // контроллер, а не даём исходному выполниться и решать самому.
        $event->setController(static fn (): JsonResponse => new JsonResponse(
            new ValidationErrorResponse(status: Response::HTTP_FORBIDDEN, code: 'company_access_denied', message: 'Company is not accessible.'),
            Response::HTTP_FORBIDDEN,
        ));
    }
}
