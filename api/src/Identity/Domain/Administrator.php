<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\AdminRole;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Учётная запись администратора сервиса (ADR-007, ADR-017).
 *
 * Своя таблица, а не флаг в `user`: ADR-007 отверг флаг явно — «одна
 * забытая проверка отделяет от раскрытия данных всех клиентов».
 * Администратор не участник ни одной компании и в company_member
 * не появляется; доступ к данным клиента он получает не членством,
 * а системным контуром.
 *
 * Роль здесь — настоящая Symfony-роль, в отличие от CompanyMemberRole:
 * getRoles() возвращает её, и на ней же стоит role_hierarchy.
 *
 * Правится только на месте одно поле — пароль, и то механизма смены
 * сегодня нет. Переходов состояния у сущности нет вовсе, поэтому
 * версия (ADR-008) не заводится: конкурировать нечем.
 */
#[ORM\Entity]
#[ORM\Table(name: 'administrator')]
#[ORM\UniqueConstraint(name: 'uq_administrator_email', columns: ['email'])]
#[ORM\Index(name: 'idx_administrator_created_by', columns: ['created_by_admin_id'])]
class Administrator implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private readonly Uuid $id;

    #[ORM\Column(length: 255)]
    private readonly string $email;

    #[ORM\Column(length: 255)]
    private string $passwordHash;

    #[ORM\Column(length: 32, enumType: AdminRole::class)]
    private readonly AdminRole $role;

    /**
     * Кто завёл. Nullable ровно для первого `SuperAdmin`: его заводит
     * консольная команда, и автора у него в системе нет по построению.
     * У всех остальных заполнено — это и есть след ADR-011 на самой
     * строке, дополняющий запись журнала.
     *
     * Ассоциация, а не scalar-поле с uuid, — единственное исключение
     * из общего стиля этого проекта, и оно вынужденное: внешний ключ
     * `fk_administrator_author` существует в базе (он и делает
     * утверждение «автор существует» правдой), а Doctrine строит FK
     * только по ассоциациям. Со scalar-полем маппинг расходился бы
     * со схемой, и `migrations:diff` однажды предложил бы ключ удалить.
     *
     * Правило CLAUDE.md §6 про scalar-поля этим не нарушено: оно
     * о ссылках на сущность ДРУГОГО модуля, а здесь ссылка на соседнюю
     * строку той же таблицы.
     */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'created_by_admin_id', referencedColumnName: 'id', nullable: true)]
    private readonly ?self $createdBy;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    private function __construct(
        Uuid $id,
        string $email,
        string $passwordHash,
        AdminRole $role,
        ?self $createdBy,
        \DateTimeImmutable $createdAt,
    ) {
        $this->id = $id;
        $this->email = $email;
        $this->passwordHash = $passwordHash;
        $this->role = $role;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
    }

    public static function create(
        string $email,
        string $passwordHash,
        AdminRole $role,
        ?self $createdBy,
    ): self {
        return new self(Uuid::v7(), self::normalizeEmail($email), $passwordHash, $role, $createdBy, new \DateTimeImmutable());
    }

    /**
     * Одно место нормализации на этот контур — используется и при
     * сохранении, и при поиске (DoctrineAdministratorRepository,
     * AdminProvider), иначе регистр расходится между записью и чтением.
     *
     * Повторяет строку из User намеренно, а не зовёт её: контуры
     * по ADR-007 независимы, и импорт User сюда связал бы их ради
     * одного mb_strtolower(trim()). Общий класс на одну эту строку
     * был бы больше правила, которое он держит.
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function role(): AdminRole
    {
        return $this->role;
    }

    public function createdByAdminId(): ?Uuid
    {
        return $this->createdBy?->id();
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /**
     * Одна роль, не список: вышестоящую доклеивает role_hierarchy
     * (ADR-017), а не эта сущность.
     *
     * @return list<non-empty-string>
     */
    public function getRoles(): array
    {
        return [$this->role->securityRole()];
    }

    public function eraseCredentials(): void
    {
    }
}
