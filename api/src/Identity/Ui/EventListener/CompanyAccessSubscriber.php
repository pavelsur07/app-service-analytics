<?php

declare(strict_types=1);

namespace App\Identity\Ui\EventListener;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\CompanyStatus;
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
 * Заблокированный аккаунт (ADR-017) отказывает здесь же и той же
 * проверкой: подписчик срабатывает по атрибуту маршрута companyId
 * независимо от firewall, поэтому контур расширения (ADR-010) закрыт
 * тем же кодом. Блокировка действует со следующего запроса, сбрасывать
 * сессии в Redis не требуется.
 *
 * 401 (не аутентифицирован) отдаёт security.access_control раньше, чем
 * запрос доходит до kernel.controller (ApiAuthenticationEntryPoint) —
 * но полагаться на это здесь нельзя: подписчик находит маршрут
 * по имени параметра, а access_control — по префиксу пути, и совпадение
 * этих двух множеств ничем не проверяется. Поэтому всё, что не продавец
 * и не администратор, получает отказ, а не проход.
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

        // Администратор — другой контур (ADR-007: своя таблица, свой
        // firewall; ADR-017: роли внутри контура). Членства
        // в company_member у него нет по построению, и проверять здесь
        // нечего: доступ системного контура даёт роль, а не членство.
        // Роль проверяют access_control и #[IsGranted] на самих
        // маршрутах — этот подписчик о них не знает и знать не должен.
        if ($user instanceof Administrator) {
            return;
        }

        // Всё остальное, включая отсутствие аутентификации, — отказ.
        // Раньше здесь стоял assert($user instanceof User): в dev он
        // ловил бы такой случай, а в prod с выключенными assert
        // выражение ниже упало бы на null. Теперь случай реальный —
        // с появлением Administrator тип пользователя перестал быть
        // единственным, — и отвечать на него надо отказом, а не
        // проходом дальше.
        if (!$user instanceof User) {
            $event->setController($this->deny('company_access_denied', 'Company is not accessible.'));

            return;
        }

        $status = $this->companyMembers->findAccessStatus($companyId, $user->id()->toRfc4122());

        if (null === $status) {
            $event->setController($this->deny('company_access_denied', 'Company is not accessible.'));

            return;
        }

        if (CompanyStatus::Blocked === $status) {
            // Отдельный код, а не общий отказ: клиенту надо отличить
            // «эта компания не ваша» от «ваш аккаунт выключен» — второе
            // чинится обращением в поддержку, первое нет.
            //
            // Утечки здесь нет: статус вернулся только потому, что
            // членство подтверждено тем же запросом. Постороннему
            // по-прежнему приходит company_access_denied.
            $event->setController($this->deny('company_blocked', 'Account is blocked.'));

            return;
        }

        // Кто действует — сюда же, рядом с проверкой членства:
        // аудит-журнал (ADR-007) требует автора у каждой записи,
        // а контроллеры чужих модулей класс User импортировать
        // не могут (зависимости строго вниз).
        $event->getRequest()->attributes->set(RequestAttributes::ActorUserId, $user->id()->toRfc4122());
    }

    /**
     * Тело без данных компании (ТЗ, критерий приёмки 3) — заменяем
     * контроллер, а не даём исходному выполниться и решать самому.
     */
    private function deny(string $code, string $message): \Closure
    {
        return static fn (): JsonResponse => new JsonResponse(
            new ValidationErrorResponse(status: Response::HTTP_FORBIDDEN, code: $code, message: $message),
            Response::HTTP_FORBIDDEN,
        );
    }
}
