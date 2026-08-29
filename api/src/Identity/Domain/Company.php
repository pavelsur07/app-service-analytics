<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Identity\Domain\ValueObject\CompanyStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Арендатор (ADR-002). Не final — Doctrine строит proxy наследованием
 * (docs/patterns.md, «Модификаторы классов»).
 *
 * Статус здесь только читается. Меняют его именованные операции
 * репозитория (`blockIfActive`, `activateIfBlocked`), а не сеттер:
 * условие перехода обязано жить внутри самого `UPDATE`, иначе проверка
 * и запись расходятся между транзакциями (ADR-011 п.4, CLAUDE.md §4).
 * Доменный метод, пишущий через ORM, этому требованию не отвечает —
 * поэтому его здесь и нет.
 *
 * Отсюда следствие: у сущности, загруженной до перехода, статус
 * устаревает. Сегодня это никого не задевает — `Company` по одному лишь
 * идентификатору не читается вовсе (CLAUDE.md §1), и метода для этого
 * в репозитории нет.
 */
#[ORM\Entity]
#[ORM\Table(name: 'company')]
class Company
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 32, enumType: CompanyStatus::class)]
    private CompanyStatus $status;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(Uuid $id, string $name, CompanyStatus $status, \DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->name = $name;
        $this->status = $status;
        $this->createdAt = $createdAt;
    }

    public static function register(string $name): self
    {
        return new self(Uuid::v7(), $name, CompanyStatus::Active, new \DateTimeImmutable());
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function status(): CompanyStatus
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
