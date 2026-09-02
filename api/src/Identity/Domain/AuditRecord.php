<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Запись аудит-журнала (ADR-007, CLAUDE.md «Безопасность и аудит»):
 * изменение себестоимости, изменение планов, добавление и изменение
 * учётных данных подключений, вход администратора в данные клиента.
 *
 * Никогда не изменяется — только добавляется. Единственное удаление
 * (`company.registered`) происходит вместе со всем неподтверждённым
 * self-signup аккаунтом по сроку хранения ADR-021: этот человек не стал
 * клиентом, а email в `newValue` остаётся его персональными данными.
 * Любая другая запись блокирует уборку целой компании. Это стирание всего
 * заброшенного графа, не возможность «поправить» историю живого клиента.
 *
 * «Было» и «стало» обязательны (ADR-011): у данных, которые правятся
 * на месте, прежнее значение исчезает, и журнал без него отвечает
 * на «кто», но не на «что изменилось».
 *
 * Для секретов там не значение, а отпечаток: сам ключ в журнале — это
 * тот же секрет, только в таблице без шифрования (ADR-007 требует
 * обратного). Отпечаток отвечает на вопрос, ради которого запись
 * и делается: «тот же ключ или другой».
 *
 * Два контура — два актора (ADR-017). Продавец и администратор живут
 * в разных таблицах (ADR-007: признак администратора не выражается
 * флагом в общей таблице), поэтому одной колонкой «кто» их не описать:
 * одинаковый uuid в общем поле не сказал бы, в какой таблице искать.
 * Ровно один из двух акторов задан всегда — это проверяет CHECK
 * в самой базе, а не добросовестность вызывающего.
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_record')]
#[ORM\Index(name: 'idx_audit_record_company_occurred', columns: ['company_id', 'occurred_at'])]
class AuditRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    /**
     * Компания, которой касается событие. Nullable с ADR-017: у события
     * системного контура («заведён Admin») компании нет вообще —
     * администратор не принадлежит ни одному арендатору.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private readonly ?Uuid $companyId;

    /**
     * Кто совершил действие, если это продавец. У каждого записываемого
     * сюда события есть человек — системные задачи в журнал не пишут,
     * их след это логи и raw-слой, — но человек бывает двух видов:
     * либо продавец здесь, либо администратор в actorAdminId.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private readonly ?Uuid $actorUserId;

    /**
     * Кто совершил действие, если это администратор системного контура
     * (ADR-017). Отдельной колонкой, а не общим «actor_id»: строка
     * журнала обязана отвечать, в какой таблице искать этого человека.
     */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private readonly ?Uuid $actorAdminId;

    #[ORM\Column(length: 64)]
    private readonly string $action;

    /**
     * Над чем действие совершено — идентификатор подключения, плана,
     * позиции себестоимости. Тип объекта отдельной колонкой не хранится:
     * он однозначно следует из action, а две колонки, которые обязаны
     * согласовываться, рано или поздно разойдутся.
     */
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $subjectId;

    #[ORM\Column(type: 'text', nullable: true)]
    private readonly ?string $previousValue;

    #[ORM\Column(type: 'text', nullable: true)]
    private readonly ?string $newValue;

    #[ORM\Column]
    private readonly \DateTimeImmutable $occurredAt;

    private function __construct(
        Uuid $id,
        ?Uuid $companyId,
        ?Uuid $actorUserId,
        ?Uuid $actorAdminId,
        string $action,
        Uuid $subjectId,
        ?string $previousValue,
        ?string $newValue,
        \DateTimeImmutable $occurredAt,
    ) {
        $this->id = $id;
        $this->companyId = $companyId;
        $this->actorUserId = $actorUserId;
        $this->actorAdminId = $actorAdminId;
        $this->action = $action;
        $this->subjectId = $subjectId;
        $this->previousValue = $previousValue;
        $this->newValue = $newValue;
        $this->occurredAt = $occurredAt;
    }

    /**
     * Действие продавца в своей компании. Сигнатура не менялась
     * с появлением второго актора — вызывающие остались как были.
     */
    public static function record(
        Uuid $companyId,
        Uuid $actorUserId,
        string $action,
        Uuid $subjectId,
        ?string $previousValue,
        ?string $newValue,
        \DateTimeImmutable $occurredAt,
    ): self {
        return new self(Uuid::v7(), $companyId, $actorUserId, null, $action, $subjectId, $previousValue, $newValue, $occurredAt);
    }

    /**
     * Действие администратора системного контура (ADR-017). companyId
     * nullable: «заблокирован аккаунт» относится к компании, «заведён
     * Admin» — ни к какой.
     */
    public static function recordByAdmin(
        ?Uuid $companyId,
        Uuid $actorAdminId,
        string $action,
        Uuid $subjectId,
        ?string $previousValue,
        ?string $newValue,
        \DateTimeImmutable $occurredAt,
    ): self {
        return new self(Uuid::v7(), $companyId, null, $actorAdminId, $action, $subjectId, $previousValue, $newValue, $occurredAt);
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): ?Uuid
    {
        return $this->companyId;
    }

    public function actorUserId(): ?Uuid
    {
        return $this->actorUserId;
    }

    public function actorAdminId(): ?Uuid
    {
        return $this->actorAdminId;
    }

    public function action(): string
    {
        return $this->action;
    }

    public function subjectId(): Uuid
    {
        return $this->subjectId;
    }

    public function previousValue(): ?string
    {
        return $this->previousValue;
    }

    public function newValue(): ?string
    {
        return $this->newValue;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
