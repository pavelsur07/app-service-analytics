<?php

declare(strict_types=1);

namespace App\Advertising\Infrastructure\Repository;

use App\Advertising\Application\ObservationInput;
use Doctrine\DBAL\Connection;

final readonly class ObservationProjection
{
    public function __construct(private Connection $db)
    {
    }

    public function accept(string $companyId, ObservationInput $input): void
    {
        if ($companyId !== $input->companyId) {
            throw new \InvalidArgumentException('scope_mismatch');
        }
        $this->db->executeStatement('INSERT INTO advertising_observation_state(company_id, policy_id, account_id, applied_version, day, spend_minor, difference_minor, currency, action, reason, latched, evaluated_at)
            SELECT p.company_id,p.id,p.account_id,:version,:day,:spend,:difference,:currency,:action,:reason,:latched,:at FROM advertising_observation_policy p
            WHERE p.company_id=:company AND p.id=:policy AND p.account_id=:account AND p.version=:version
            ON CONFLICT(company_id,policy_id) DO UPDATE SET account_id=EXCLUDED.account_id,applied_version=EXCLUDED.applied_version,day=EXCLUDED.day,spend_minor=EXCLUDED.spend_minor,difference_minor=EXCLUDED.difference_minor,currency=EXCLUDED.currency,action=EXCLUDED.action,reason=EXCLUDED.reason,latched=EXCLUDED.latched,evaluated_at=EXCLUDED.evaluated_at
            WHERE advertising_observation_state.applied_version<=EXCLUDED.applied_version AND advertising_observation_state.evaluated_at<EXCLUDED.evaluated_at',
            ['company' => $companyId, 'policy' => $input->policyId, 'account' => $input->accountId, 'version' => $input->version, 'day' => $input->day, 'spend' => $input->spend?->minorAmount(), 'difference' => $input->difference?->minorAmount(), 'currency' => 'RUB', 'action' => $input->action, 'reason' => $input->reason, 'latched' => $input->latched, 'at' => $input->at->format('Y-m-d H:i:s.uP')], ['latched' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
    }
}
