<?php

declare(strict_types=1);

namespace App\Advertising\Application;

use App\Advertising\Domain\ObservationEvent;
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

        return $this->policies->transactional(function () use ($policy, $input, $actor): ObservationPolicy {
            $this->policies->add($policy);
            $this->event($policy, $input, $actor, 1);
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
            $nextVersion = $policy->version() + 1;
            $policy->configure($input->dailyLimit, $input->start, $input->stop, $input->pollSeconds, $input->enabled, Uuid::fromString($actor), $this->clock->now());
            $this->event($policy, $input, $actor, $nextVersion);
            $this->identity->recordAuditEntry($companyId, $actor, 'advertising.observe_updated', $policyId, null, '{"mode":"observe"}');

            return $policy;
        });
    }

    private function event(ObservationPolicy $policy, PolicyInput $input, string $actor, int $version): void
    {
        $this->policies->recordEvent(new ObservationEvent($policy->companyId(), $policy->id(), $policy->accountId(), $policy->campaignId(), $version, 'policy:'.$version, 1 === $version ? 'policy_created' : 'policy_updated', Uuid::fromString($actor), 'saved', 'user_change', ['start' => $input->start, 'stop' => $input->stop, 'pollSeconds' => $input->pollSeconds, 'enabled' => $input->enabled], $this->clock->now(), $input->dailyLimit));
    }
}
