<?php

declare(strict_types=1);

namespace App\Identity\Ui\Controller\Admin;

use App\Identity\Application\RecordAdminCompanyViewAction;
use App\Identity\Domain\Administrator;
use App\Identity\Infrastructure\Query\AdminCompanyAuditRow;
use App\Identity\Infrastructure\Query\AdminCompanyMemberRow;
use App\Identity\Infrastructure\Query\CompanyConnectionRow;
use App\Identity\Infrastructure\Query\CompanyConnectionsQuery;
use App\Identity\Infrastructure\Query\CompanyForAdminQuery;
use App\Identity\Ui\Response\Admin\AdminCompanyAuditResponse;
use App\Identity\Ui\Response\Admin\AdminCompanyConnectionResponse;
use App\Identity\Ui\Response\Admin\AdminCompanyDetailResponse;
use App\Identity\Ui\Response\Admin\AdminCompanyMemberResponse;
use App\Identity\Ui\Response\AdminCompanyResponse;
use App\Shared\Ui\Response\ValidationErrorResponse;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Карточка компании в системном контуре (CLAUDE.md §1, ADR-017, ADR-018).
 * Собственный class-level слой IdentityAdminCompanyUi: широкому Ui продавца
 * CompanyForAdminQuery недоступен. Членство администратора не требуется.
 */
#[Route('/api/admin/companies/{id}', name: 'identity_admin_company_detail', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
final readonly class GetClientAccountController
{
    public function __construct(private CompanyForAdminQuery $company, private CompanyConnectionsQuery $connections, private RecordAdminCompanyViewAction $recordView, private Security $security)
    {
    }

    #[OA\Response(response: 200, description: 'Компания, до 50 участников и подключений и 20 последних событий поддержки. Флаги truncated обозначают ограниченный список.', content: new Model(type: AdminCompanyDetailResponse::class))]
    #[OA\Response(response: 404, description: 'Компания не найдена', content: new Model(type: ValidationErrorResponse::class))]
    public function __invoke(string $id): JsonResponse
    {
        $raw = $this->company->build($id)->executeQuery()->fetchAssociative();
        if (false === $raw) {
            return new JsonResponse(new ValidationErrorResponse(404, 'company_not_found', 'Компания не найдена.'), Response::HTTP_NOT_FOUND);
        }
        $actor = $this->security->getUser();
        \assert($actor instanceof Administrator);
        ($this->recordView)($id, $actor);

        $company = CompanyForAdminQuery::mapCompany($raw);
        $members = array_map(CompanyForAdminQuery::mapMember(...), $this->company->members($id)->executeQuery()->fetchAllAssociative());
        $connections = array_map(CompanyConnectionsQuery::mapRow(...), $this->connections->build($id)->executeQuery()->fetchAllAssociative());
        $audit = array_map(CompanyForAdminQuery::mapAudit(...), $this->company->recentAudit($id)->executeQuery()->fetchAllAssociative());

        return new JsonResponse(new AdminCompanyDetailResponse(
            company: new AdminCompanyResponse($company->id, $company->name, $company->status, $company->createdAt, $company->hasConfirmedUser),
            members: array_map(static fn (AdminCompanyMemberRow $row): AdminCompanyMemberResponse => new AdminCompanyMemberResponse(
                $row->id, $row->email, $row->role, $row->joinedAt, $row->emailConfirmedAt,
            ), \array_slice($members, 0, CompanyForAdminQuery::MEMBERS_LIMIT)),
            membersTruncated: \count($members) > CompanyForAdminQuery::MEMBERS_LIMIT,
            connections: array_map(static fn (CompanyConnectionRow $row): AdminCompanyConnectionResponse => new AdminCompanyConnectionResponse(
                $row->id, $row->marketplace, $row->externalShopId, $row->state, $row->advertisingState, $row->createdAt,
            ), \array_slice($connections, 0, CompanyConnectionsQuery::MAX_RESULTS)),
            connectionsTruncated: \count($connections) > CompanyConnectionsQuery::MAX_RESULTS,
            recentAudit: array_map(static fn (AdminCompanyAuditRow $row): AdminCompanyAuditResponse => new AdminCompanyAuditResponse(
                $row->id, $row->action, $row->actorType, $row->actorId, $row->actorEmail, $row->occurredAt,
            ), \array_slice($audit, 0, CompanyForAdminQuery::AUDIT_LIMIT)),
            auditTruncated: \count($audit) > CompanyForAdminQuery::AUDIT_LIMIT,
        ));
    }
}
