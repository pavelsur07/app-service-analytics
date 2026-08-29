<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\ValueObject\CompanyStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Блокировка и включение клиентского аккаунта (ADR-017). Обе роли
 * системного контура это могут — проверка стоит на маршруте
 * (`#[IsGranted('ROLE_ADMIN')]`), не здесь.
 *
 * Один сценарий на оба перехода, а не два: различаются они ожидаемым
 * состоянием и действием в журнале, всё остальное — включая требование
 * атомарности перехода и следа — общее. Два класса-близнеца разошлись бы
 * ровно в том, что должно совпадать.
 *
 * Возвращает false, когда переход не понадобился: аккаунт уже в целевом
 * состоянии. Это не ошибка — повторное нажатие кнопки не должно ни
 * падать, ни писать второй след.
 */
final readonly class ChangeCompanyStatusAction
{
    public function __construct(
        private CompanyRepository $companies,
    ) {
    }

    public function __invoke(string $companyId, CompanyStatus $to, Administrator $actor): bool
    {
        $from = match ($to) {
            CompanyStatus::Blocked => CompanyStatus::Active,
            CompanyStatus::Active => CompanyStatus::Blocked,
        };

        // «Было» и «стало» — сами статусы (ADR-011). Компания в записи
        // указана, в отличие от событий вроде «заведён Admin»: это
        // событие про конкретного арендатора, и искать его будут от него.
        $trail = AuditRecord::recordByAdmin(
            companyId: Uuid::fromString($companyId),
            actorAdminId: $actor->id(),
            action: match ($to) {
                CompanyStatus::Blocked => AuditAction::CompanyBlocked,
                CompanyStatus::Active => AuditAction::CompanyActivated,
            },
            subjectId: Uuid::fromString($companyId),
            previousValue: $from->value,
            newValue: $to->value,
            occurredAt: new \DateTimeImmutable(),
        );

        return match ($to) {
            CompanyStatus::Blocked => $this->companies->blockIfActive($companyId, $trail),
            CompanyStatus::Active => $this->companies->activateIfBlocked($companyId, $trail),
        };
    }
}
