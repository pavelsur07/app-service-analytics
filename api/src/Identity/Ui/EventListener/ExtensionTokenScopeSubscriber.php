<?php

declare(strict_types=1);

namespace App\Identity\Ui\EventListener;

use App\Identity\Infrastructure\Security\ExtensionTokenRequestAttributes;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Токен расширения привязан к одной компании (ADR-010). Этот подписчик
 * сверяет её с компанией из пути и отклоняет расхождение.
 *
 * Почему одной проверки CompanyAccessSubscriber мало: та отвечает
 * на вопрос «состоит ли пользователь в этой компании», и для участника
 * двух компаний ответ будет «да» для обеих. Токен, выпущенный на первую,
 * тогда читал бы данные второй — членство есть, а области действия
 * токена никто не проверил.
 *
 * Шов был оставлен в пакете 1 намеренно: атрибуты назывались не
 * `companyId`, чтобы результат проверки токена не подменял вход,
 * а сверялся с ним, когда появятся маршруты с компанией в пути.
 * Они появились — вот сверка.
 *
 * Порядок с CompanyAccessSubscriber не важен: обе проверки обязаны
 * пройти, и любая из них отдаёт 403 сама.
 */
final class ExtensionTokenScopeSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onKernelController'];
    }

    public function onKernelController(ControllerEvent $event): void
    {
        $attributes = $event->getRequest()->attributes;

        $tokenCompanyId = $attributes->get(ExtensionTokenRequestAttributes::COMPANY_ID);
        // Запрос не под токеном расширения — сверять нечего.
        if (!\is_string($tokenCompanyId)) {
            return;
        }

        $pathCompanyId = $attributes->get('companyId');
        // Маршрут без компании в пути (/api/extension/me) — тоже нечего.
        if (!\is_string($pathCompanyId)) {
            return;
        }

        if ($tokenCompanyId === $pathCompanyId) {
            return;
        }

        $event->setController(static fn (): JsonResponse => new JsonResponse(
            new ValidationErrorResponse(
                status: Response::HTTP_FORBIDDEN,
                code: 'company_access_denied',
                message: 'Company is not accessible.',
            ),
            Response::HTTP_FORBIDDEN,
        ));
    }
}
