<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AdministratorRepository;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\AuditRecordRepository;
use App\Identity\Domain\ValueObject\AdminRole;

/**
 * `SuperAdmin` заводит `Admin` (ADR-017). Единственный путь к нижней
 * роли: консольная команда её не создаёт, и роль здесь не параметр —
 * она задана в коде, а не приходит из запроса.
 *
 * Актор обязателен и типизирован `Administrator`, а не строкой: автор
 * попадает и в саму строку (`created_by_admin_id`), и в журнал, и оба
 * места база держит ограничениями. Передать сюда «кого-нибудь» нельзя.
 *
 * Приходит готовый хэш, а не пароль: хэширование — забота Ui, как
 * и у CreateUserCommand. Не стилистика, а граница слоёв — Application
 * не имеет доступа к SymfonyComponent (api/deptrac.php), и правильный
 * ответ на это ограничение здесь такой же, как у продавца.
 */
final readonly class CreateAdministratorAction
{
    public function __construct(
        private AdministratorRepository $administrators,
        private AuditRecordRepository $auditRecords,
    ) {
    }

    public function __invoke(string $email, string $passwordHash, Administrator $actor): Administrator
    {
        $administrator = Administrator::create($email, $passwordHash, AdminRole::Admin, $actor->id());

        // Запись ставится до сохранения: фиксирует её тот же flush,
        // что и сущность (см. AuditRecordRepository). Иначе возможен
        // исход, при котором администратор заведён, а следа об этом нет.
        //
        // companyId — null: событие системного контура, к арендатору
        // не относится (ADR-017). «Было» пусто, «стало» — email:
        // журнал должен отвечать, кого именно завели, а не только что
        // кого-то завели.
        $this->auditRecords->addToUnitOfWork(AuditRecord::recordByAdmin(
            companyId: null,
            actorAdminId: $actor->id(),
            action: AuditAction::AdministratorCreated,
            subjectId: $administrator->id(),
            previousValue: null,
            newValue: $administrator->email(),
            occurredAt: new \DateTimeImmutable(),
        ));

        // Конфликт по email перехватывается на вставке вызывающим
        // (CLAUDE.md §4), не проверкой перед ней.
        $this->administrators->add($administrator);

        return $administrator;
    }
}
