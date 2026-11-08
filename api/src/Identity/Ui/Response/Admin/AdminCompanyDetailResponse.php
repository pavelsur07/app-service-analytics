<?php

declare(strict_types=1);

namespace App\Identity\Ui\Response\Admin;

use App\Identity\Ui\Response\AdminCompanyResponse;

final readonly class AdminCompanyDetailResponse
{
    /**
     * @param list<AdminCompanyMemberResponse>     $members
     * @param list<AdminCompanyConnectionResponse> $connections
     * @param list<AdminCompanyAuditResponse>      $recentAudit
     */
    public function __construct(
        public AdminCompanyResponse $company,
        public array $members,
        public bool $membersTruncated,
        public array $connections,
        public bool $connectionsTruncated,
        public array $recentAudit,
        public bool $auditTruncated,
    ) {
    }
}
