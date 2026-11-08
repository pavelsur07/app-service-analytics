<?php

declare(strict_types=1);

namespace App\Advertising\Infrastructure\Repository;

use App\Advertising\Application\ObservationInput;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

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
        $this->db->executeStatement('WITH accepted AS (INSERT INTO advertising_observation_state(company_id, policy_id, account_id, applied_version, day, spend_minor, difference_minor, currency, action, reason, latched, evaluated_at)
            SELECT p.company_id,p.id,p.account_id,:version,:day,:spend,:difference,:currency,:action,:reason,:latched,:at FROM advertising_observation_policy p
            WHERE p.company_id=:company AND p.id=:policy AND p.account_id=:account AND p.version=:version
            ON CONFLICT(company_id,policy_id) DO UPDATE SET account_id=EXCLUDED.account_id,applied_version=EXCLUDED.applied_version,day=EXCLUDED.day,spend_minor=EXCLUDED.spend_minor,difference_minor=EXCLUDED.difference_minor,currency=EXCLUDED.currency,action=EXCLUDED.action,reason=EXCLUDED.reason,latched=EXCLUDED.latched,evaluated_at=EXCLUDED.evaluated_at
            WHERE advertising_observation_state.applied_version<=EXCLUDED.applied_version AND advertising_observation_state.evaluated_at<EXCLUDED.evaluated_at
            RETURNING company_id,policy_id,account_id)
            INSERT INTO advertising_observation_event(company_id,id,policy_id,account_id,campaign_id,version,event_key,kind,actor_id,action,reason,limit_minor,spend_minor,difference_minor,currency,details,occurred_at)
            SELECT a.company_id,:event,a.policy_id,a.account_id,p.campaign_id,:version,:event_key,\'observation\',NULL,:action,:reason,NULL,:spend,:difference,:currency,:details,:at FROM accepted a
            JOIN advertising_observation_policy p ON p.company_id=a.company_id AND p.id=a.policy_id
            ON CONFLICT(company_id,policy_id,event_key) DO NOTHING',
            ['event' => Uuid::v7()->toRfc4122(), 'event_key' => hash('sha256', $input->version.':'.$input->at->format('Y-m-d H:i:s.uP')), 'details' => json_encode(['day' => $input->day, 'latched' => $input->latched], \JSON_THROW_ON_ERROR), 'company' => $companyId, 'policy' => $input->policyId, 'account' => $input->accountId, 'version' => $input->version, 'day' => $input->day, 'spend' => $input->spend?->minorAmount(), 'difference' => $input->difference?->minorAmount(), 'currency' => 'RUB', 'action' => $input->action, 'reason' => $input->reason, 'latched' => $input->latched, 'at' => $input->at->format('Y-m-d H:i:s.uP')], ['latched' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
    }
}
