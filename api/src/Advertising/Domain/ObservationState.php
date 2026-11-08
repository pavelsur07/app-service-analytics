<?php

declare(strict_types=1);

namespace App\Advertising\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/** Schema for the latest company-scoped service projection, written by DBAL. */
#[ORM\Entity]
#[ORM\Table(name: 'advertising_observation_state')]
#[ORM\Index(name: 'idx_ads_state_account', columns: ['company_id', 'account_id'])]
class ObservationState
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $policyId;
    #[ORM\Column(type: 'uuid')]
    private Uuid $accountId;
    #[ORM\Column]
    private int $appliedVersion;
    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $day;
    #[ORM\Column(type: 'money_minor_amount', nullable: true)]
    private ?int $spendMinor;
    #[ORM\Column(type: 'money_minor_amount', nullable: true)]
    private ?int $differenceMinor;
    #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
    private string $currency;
    #[ORM\Column(length: 16)]
    private string $action;
    #[ORM\Column(length: 64)]
    private string $reason;
    #[ORM\Column]
    private bool $latched;
    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $evaluatedAt;

    public function __construct(Uuid $companyId, Uuid $policyId, Uuid $accountId, int $appliedVersion, \DateTimeImmutable $day, ?Money $spend, ?Money $difference, string $action, string $reason, bool $latched, \DateTimeImmutable $at)
    {
        foreach ([$spend, $difference] as $amount) {
            if (null !== $amount && ('RUB' !== $amount->currency() || $amount->minorAmount() < 0)) {
                throw new \InvalidArgumentException('invalid_observation_money');
            }
        }
        $this->companyId = $companyId;
        $this->policyId = $policyId;
        $this->accountId = $accountId;
        $this->appliedVersion = $appliedVersion;
        $this->day = $day;
        $this->spendMinor = $spend?->minorAmount();
        $this->differenceMinor = $difference?->minorAmount();
        $this->currency = 'RUB';
        $this->action = $action;
        $this->reason = $reason;
        $this->latched = $latched;
        $this->evaluatedAt = $at;
    }
}
