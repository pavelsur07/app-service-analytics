<?php

declare(strict_types=1);

namespace App\Advertising\Domain;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'advertising_observation_policy')]
#[ORM\UniqueConstraint(name: 'uq_ads_policy_campaign', columns: ['company_id', 'account_id', 'campaign_id'])]
#[ORM\Index(name: 'idx_ads_policy_delivery', columns: ['id'])]
#[ORM\Index(name: 'idx_ads_policy_actor', columns: ['company_id', 'updated_by'])]
class ObservationPolicy
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $companyId;
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;
    #[ORM\Column(type: 'uuid')]
    private Uuid $accountId;
    #[ORM\Column(length: 20)]
    private string $campaignId;
    #[ORM\Column(type: 'money_minor_amount')]
    private int $dailyLimitMinor;
    #[ORM\Column(type: 'string', length: 3, options: ['fixed' => true])]
    private string $currency;
    #[ORM\Column(length: 5)]
    private string $startTime;
    #[ORM\Column(length: 5)]
    private string $stopTime;
    #[ORM\Column]
    private int $pollSeconds;
    #[ORM\Column]
    private bool $enabled;
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;
    #[ORM\Column(type: 'uuid')]
    private Uuid $updatedBy;
    #[ORM\Column(type: 'datetimetz_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $companyId, Uuid $accountId, string $campaignId, Money $dailyLimit, string $start, string $stop, int $pollSeconds, bool $enabled, Uuid $actor, \DateTimeImmutable $at)
    {
        if (1 !== preg_match('/\A[1-9][0-9]{0,18}\z/', $campaignId)) {
            throw new \InvalidArgumentException('invalid_campaign');
        }
        $this->companyId = $companyId;
        $this->id = Uuid::v7();
        $this->accountId = $accountId;
        $this->campaignId = $campaignId;
        $this->configure($dailyLimit, $start, $stop, $pollSeconds, $enabled, $actor, $at);
    }

    public function configure(Money $dailyLimit, string $start, string $stop, int $pollSeconds, bool $enabled, Uuid $actor, \DateTimeImmutable $at): void
    {
        if ('RUB' !== $dailyLimit->currency() || $dailyLimit->minorAmount() < 1 || $dailyLimit->minorAmount() > 9007199254740991 || !self::validClock($start) || !self::validClock($stop) || $start >= $stop || $pollSeconds < 600 || $pollSeconds > 86400) {
            throw new \InvalidArgumentException('invalid_policy');
        }
        $this->dailyLimitMinor = $dailyLimit->minorAmount();
        $this->currency = $dailyLimit->currency();
        $this->startTime = $start;
        $this->stopTime = $stop;
        $this->pollSeconds = $pollSeconds;
        $this->enabled = $enabled;
        $this->updatedBy = $actor;
        $this->updatedAt = $at;
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function companyId(): Uuid
    {
        return $this->companyId;
    }

    public function accountId(): Uuid
    {
        return $this->accountId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function dailyLimit(): Money
    {
        return Money::ofMinor($this->dailyLimitMinor, $this->currency);
    }

    private static function validClock(string $clock): bool
    {
        return 1 === preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $clock);
    }
}
