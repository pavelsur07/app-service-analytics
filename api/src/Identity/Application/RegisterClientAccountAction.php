<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\Administrator;
use App\Identity\Domain\AuditAction;
use App\Identity\Domain\AuditRecord;
use App\Identity\Domain\Company;
use App\Identity\Domain\CompanyMember;
use App\Identity\Domain\CompanyRepository;
use App\Identity\Domain\EmailVerificationToken;
use App\Identity\Domain\RegistrationEmailSender;
use App\Identity\Domain\User;
use App\Identity\Domain\ValueObject\CompanyMemberRole;
use App\Identity\Domain\ValueObject\EmailVerificationSecret;

/**
 * Регистрация клиентского аккаунта администратором (ADR-017): компания
 * и её первый владелец за одно действие.
 *
 * Раньше то же делали две консольные команды подряд, и между ними
 * существовало состояние «компания без участников» — сюда оно
 * не переносится: всё уходит одной транзакцией
 * (`CompanyRepository::registerWithOwner`).
 *
 * Приходит готовый хэш, а не пароль: хэширование — забота Ui, как
 * у CreateAdministratorAction и CreateUserCommand (Application
 * не имеет доступа к SymfonyComponent).
 */
final readonly class RegisterClientAccountAction
{
    public function __construct(
        private CompanyRepository $companies,
        private RegistrationEmailSender $registrationEmails,
    ) {
    }

    public function __invoke(
        string $companyName,
        string $ownerEmail,
        string $ownerPasswordHash,
        Administrator $actor,
    ): ?Company {
        $company = Company::register($companyName);
        $owner = User::register($ownerEmail, $ownerPasswordHash);
        $membership = CompanyMember::create($company->id(), $owner->id(), CompanyMemberRole::Owner);

        // «Стало» — email владельца, а не название компании: название
        // видно в самой строке компании, а вот кому отдали доступ
        // к аккаунту, кроме журнала, не хранит никто.
        $trail = AuditRecord::recordByAdmin(
            companyId: $company->id(),
            actorAdminId: $actor->id(),
            action: AuditAction::CompanyRegistered,
            subjectId: $company->id(),
            previousValue: null,
            newValue: $owner->email(),
            occurredAt: new \DateTimeImmutable(),
        );

        if (!$this->companies->registerWithOwner($company, $owner, $membership, $trail)) {
            return null;
        }

        return $company;
    }

    public function selfRegister(
        string $companyName,
        string $ownerEmail,
        string $passwordHash,
        \DateTimeImmutable $consentedAt,
        string $documentsVersion,
    ): SelfRegistrationResult {
        $owner = User::selfRegister($ownerEmail, $passwordHash, $consentedAt, $documentsVersion);
        $company = Company::register($companyName);
        $membership = CompanyMember::create($company->id(), $owner->id(), CompanyMemberRole::Owner);
        $secret = EmailVerificationSecret::generate();
        $token = EmailVerificationToken::issue($owner->id(), $secret->hash(), $consentedAt);
        $trail = AuditRecord::record(
            companyId: $company->id(),
            actorUserId: $owner->id(),
            action: AuditAction::CompanyRegistered,
            subjectId: $company->id(),
            previousValue: null,
            newValue: $owner->email(),
            occurredAt: $consentedAt,
        );

        $created = $this->companies->registerWithOwner($company, $owner, $membership, $trail, $token);

        if ($created) {
            $this->registrationEmails->sendConfirmation($owner->email(), $secret);
        } else {
            $this->registrationEmails->sendAlreadyRegistered($owner->email());
        }

        return new SelfRegistrationResult($created);
    }
}
