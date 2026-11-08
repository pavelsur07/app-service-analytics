<?php

declare(strict_types=1);

namespace App\Advertising\Application;

use App\Advertising\Domain\ObservationPolicy;
use App\Advertising\Domain\ObservationPolicyRepository;
use App\Identity\Application\Facade\IdentityFacade;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class SaveObservationPolicyAction
{
    public function __construct(private ObservationPolicyRepository $policies, private IdentityFacade $identity, private ClockInterface $clock)
    {
    }

    public function create(string $companyId, string $accountId, string $campaignId, PolicyInput $input, string $actor): ObservationPolicy
    {
        if (null === $this->identity->findOzonAdvertisingTarget($companyId, $accountId)) {
            throw new \DomainException('account_unavailable');
        }
        $policy = new ObservationPolicy(Uuid::fromString($companyId), Uuid::fromString($accountId), $campaignId, $input->dailyLimit, $input->start, $input->stop, $input->pollSeconds, $input->enabled, Uuid::fromString($actor), $this->clock->now());

        return $this->policies->transactional(function () use ($policy, $actor): ObservationPolicy {
            $this->policies->add($policy);
            $this->identity->recordAuditEntry($policy->companyId()->toRfc4122(), $actor, 'advertising.observe_created', $policy->id()->toRfc4122(), null, '{"mode":"observe"}');

            return $policy;
        });
    }

    public function update(string $companyId, string $policyId, int $version, PolicyInput $input, string $actor): ObservationPolicy
    {
        return $this->policies->transactional(function () use ($companyId, $policyId, $version, $input, $actor): ObservationPolicy {
            $policy = $this->policies->locked($companyId, $policyId, $version);
            if ($input->enabled && null === $this->identity->findOzonAdvertisingTarget($companyId, $policy->accountId()->toRfc4122())) {
                throw new \DomainException('account_unavailable');
            }
            $policy->configure($input->dailyLimit, $input->start, $input->stop, $input->pollSeconds, $input->enabled, Uuid::fromString($actor), $this->clock->now());
            $this->identity->recordAuditEntry($companyId, $actor, 'advertising.observe_updated', $policyId, null, '{"mode":"observe"}');

            return $policy;
        });
    }
}
