<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\CompanyMember;
use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\ValueObject\CompanyMemberRole;
use Symfony\Component\Uid\Uuid;

/**
 * Членство существующего пользователя во второй компании.
 *
 * CreateUserWithMembershipAction этого не умеет и не должен: он заводит
 * учётную запись, и на существующем email упирается в уникальный индекс.
 * Разделены потому, что это разные события — «человек появился»
 * и «человеку дали доступ ещё куда-то».
 *
 * Поиск по email здесь не нарушает CLAUDE.md §1: User не данные компании
 * (ADR-002), companyId у него нет, и правило про company-scoped чтение
 * к нему не относится. Это ручная операция поддержки, не пользовательский
 * сценарий.
 *
 * Возвращает null, когда пользователя с таким email нет: вызывающий
 * превращает это в понятную ошибку, а не в членство, ведущее в никуда.
 */
final readonly class AddCompanyMemberAction
{
    public function __construct(
        private UserRepository $users,
        private CompanyMemberRepository $companyMembers,
    ) {
    }

    public function __invoke(string $email, Uuid $companyId, CompanyMemberRole $role): ?CompanyMember
    {
        $user = $this->users->findByEmail($email);
        if (null === $user) {
            return null;
        }

        $member = CompanyMember::create($companyId, $user->id(), $role);
        $this->companyMembers->add($member);

        return $member;
    }
}
