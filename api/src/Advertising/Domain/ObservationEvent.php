<?php

declare(strict_types=1);

namespace App\Advertising\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Company-scoped journal. Policy changes use ORM; service facts use DBAL. */
#[ORM\Entity]
#[ORM\Table(name: 'advertising_observation_event')]
#[ORM\UniqueConstraint(name: 'uq_ads_event_key', columns: ['company_id', 'policy_id', 'event_key'])]
#[ORM\Index(name: 'idx_ads_event_actor', columns: ['company_id', 'actor_id'])]
#[ORM\Index(name: 'idx_ads_event_account', columns: ['company_id', 'account_id', 'id'])]
class ObservationEvent
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;
    #[ORM\Column(type: 'uuid')]
    private Uuid $policyId;
    #[ORM\Column(type: 'uuid')]
    private Uuid $accountId;
    #[ORM\Column(length: 20)]
    private string $campaignId;
    #[ORM\Column]
    private int $version;
    #[ORM\Column(length: 64)]
    private string $eventKey;
    #[ORM\Column(length: 24)]
    private string $kind;
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $actorId;
    #[ORM\Column(length: 16)]
    private string $action;
    #[ORM\Column(length: 64)]
    private string $reason;
    #[ORM\Column(type: 'money_minor_amount', nullable: true)]
    private ?int $limitMinor;
    #[ORM\Column(type: 'money_minor_amount', nullable: true)]
    private ?int $spendMinor;
    #[ORM\Column(type: 'money_minor_amount', nullable: true)]
    private ?int $differenceMinor;
    #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
    private string $currency;
    /** @var array<string, int|string|bool|null> */
    #[ORM\Column(type: 'json')]
    private array $details;
    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $occurredAt;

    /** @param array<string, int|string|bool|null> $details */
    public function __construct(Uuid $companyId, Uuid $policyId, Uuid $accountId, string $campaignId, int $version, string $eventKey, string $kind, ?Uuid $actorId, string $action, string $reason, array $details, \DateTimeImmutable $at, ?Money $limit = null, ?Money $spend = null, ?Money $difference = null)
    {
        foreach ([$limit, $spend, $difference] as $money) {
            if (null !== $money && ('RUB' !== $money->currency() || $money->minorAmount() < 0 || $money->minorAmount() > 9007199254740991)) {
                throw new \InvalidArgumentException('invalid_event_money');
            }
        }
        $this->limitMinor = $limit?->minorAmount();
        $this->spendMinor = $spend?->minorAmount();
        $this->differenceMinor = $difference?->minorAmount();
        $this->currency = 'RUB';
        $this->companyId = $companyId;
        $this->id = Uuid::v7();
        $this->policyId = $policyId;
        $this->accountId = $accountId;
        $this->campaignId = $campaignId;
        $this->version = $version;
        $this->eventKey = $eventKey;
        $this->kind = $kind;
        $this->actorId = $actorId;
        $this->action = $action;
        $this->reason = $reason;
        $this->details = $details;
        $this->occurredAt = $at;
    }
}
