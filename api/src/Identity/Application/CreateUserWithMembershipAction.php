<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\CompanyMember;
use App\Identity\Domain\CompanyMemberRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Identity\Domain\ValueObject\CompanyMemberRole;
use Symfony\Component\Uid\Uuid;

/**
 * Ручной онбординг (ADR-007) — саморегистрация отложена. Хэш пароля
 * считает вызывающий код (консольная команда), Domain пароль не хэширует
 * — симметрично RegisterCompanyWithOzonAccountAction, где шифрование
 * учётных данных площадки тоже делает вызывающий код через переданный
 * сервис, не Action и не Entity.
 *
 * Два отдельных add() — та же граница, что у RegisterCompanyWithOzonAccountAction:
 * Application не видит EntityManagerInterface, обернуть оба flush в одну
 * транзакцию отсюда нечем. Частичный сбой между шагами оставил бы User
 * без CompanyMember — редкий случай для ручной консольной команды
 * с оператором, видящим результат; уникальный индекс на email не даст
 * создать второго такого же User при повторном запуске.
 */
final readonly class CreateUserWithMembershipAction
{
    public function __construct(
        private UserRepository $users,
        private CompanyMemberRepository $companyMembers,
    ) {
    }

    public function __invoke(string $email, string $passwordHash, Uuid $companyId, CompanyMemberRole $role): User
    {
        $user = User::register($email, $passwordHash);
        $this->users->add($user);

        $member = CompanyMember::create($companyId, $user->id(), $role);
        $this->companyMembers->add($member);

        return $user;
    }
}
