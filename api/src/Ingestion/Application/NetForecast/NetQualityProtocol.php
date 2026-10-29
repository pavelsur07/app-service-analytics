<?php

declare(strict_types=1);

namespace App\Ingestion\Application\NetForecast;

/** Pre-outcome v1 design. Changing these parameters requires a new prospective protocol. */
final readonly class NetQualityProtocol
{
    public const array PARAMETERS = ['model' => 'observed_retention_h60_v1', 'baseline' => 'simple_mature_return_linear_h60_v1', 'selection' => 'earliest_order_sku_v1', 'horizonDays' => 60, 'lagDays' => 7, 'returnWindowDays' => 90, 'grossWindowDays' => 90, 'trainingUnits' => 100, 'trainingPostings' => 30, 'riskUnits' => 30, 'riskPostings' => 10, 'minimumOverall' => 100, 'minimumPerGroup' => 30, 'minimumWeeks' => 2, 'minimumMoney' => 30, 'tolerance' => '0', 'readyNullShareNoWorse' => true, 'ageGroups' => ['order1_2', 'order3_5', 'order6_9', 'order10_15', 'delivery0_6', 'delivery7_29', 'delivery30_59']];
    public string $protocolId;
    public string $companyId;
    public string $accountId;
    public string $planId;
    public string $baselineId;
    public string $from;
    public string $to;
    /** @var list<string> */
    public array $skus;
    /** @var list<string> */
    public array $currencies;
    /** @var list<array{requestId: string, scheduledAtUtc: string}> */
    public array $slots;
    /** @var list<array{requestId: string, inputId: string, scheduledAtUtc: string}> */
    public array $truthSlots;
    /** @var array<string, mixed> */
    public array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        if (isset($data['parameters'])) {
            $codec = new NetForwardManifestCodec();
            if (!\is_array($data['parameters']) || $codec->encode($data['parameters']) !== $codec->encode(self::PARAMETERS)) {
                throw new \InvalidArgumentException('Unsupported quality protocol parameters.');
            }
            unset($data['parameters']);
        }
        NetQualitySchema::keys($data, ['protocolId', 'companyId', 'accountId', 'planId', 'baselineId', 'codeCommit', 'epochId', 'epochStartUtc', 'from', 'to', 'calibrationFrom', 'calibrationTo', 'holdoutFrom', 'holdoutTo', 'captureFromUtc', 'captureToUtc', 'truthCutoffUtc', 'skus', 'currencies', 'slots', 'truthSlots']);
        $this->protocolId = NetQualitySchema::uuid(NetQualitySchema::string($data, 'protocolId'), true);
        $this->companyId = NetQualitySchema::uuid(NetQualitySchema::string($data, 'companyId'));
        $this->accountId = NetQualitySchema::uuid(NetQualitySchema::string($data, 'accountId'));
        $this->planId = NetQualitySchema::uuid(NetQualitySchema::string($data, 'planId'));
        $this->baselineId = NetQualitySchema::uuid(NetQualitySchema::string($data, 'baselineId'));
        NetQualitySchema::uuid(NetQualitySchema::string($data, 'epochId'));
        NetQualitySchema::utc(NetQualitySchema::string($data, 'epochStartUtc'));
        if (1 !== preg_match('/^[a-f0-9]{40}$/D', NetQualitySchema::string($data, 'codeCommit'))) {
            throw new \InvalidArgumentException('Protocol requires its exact code commit.');
        }
        foreach (['from', 'to', 'calibrationFrom', 'calibrationTo', 'holdoutFrom', 'holdoutTo'] as $key) {
            NetQualitySchema::date(NetQualitySchema::string($data, $key));
        }
        $this->from = NetQualitySchema::string($data, 'from');
        $this->to = NetQualitySchema::string($data, 'to');
        $calFrom = NetQualitySchema::string($data, 'calibrationFrom');
        $calTo = NetQualitySchema::string($data, 'calibrationTo');
        $holdFrom = NetQualitySchema::string($data, 'holdoutFrom');
        $holdTo = NetQualitySchema::string($data, 'holdoutTo');
        if ($this->from > $this->to || (new \DateTimeImmutable($this->from))->diff(new \DateTimeImmutable($this->to))->days >= 184 || $calFrom > $calTo || $holdFrom > $holdTo || $calTo >= $holdFrom || $calFrom < $this->from || $holdTo > $this->to) {
            throw new \InvalidArgumentException('Protocol order windows overlap or exceed the capture scope.');
        }
        $this->skus = NetQualitySchema::strings($data['skus'], 50);
        $this->currencies = NetQualitySchema::strings($data['currencies'], 50);
        foreach ($this->currencies as $currency) {
            if (1 !== preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw new \InvalidArgumentException('Protocol requires explicit currency codes.');
            }
        }
        $captureFrom = NetQualitySchema::utc(NetQualitySchema::string($data, 'captureFromUtc'));
        $captureTo = NetQualitySchema::utc(NetQualitySchema::string($data, 'captureToUtc'));
        $cutoff = NetQualitySchema::utc(NetQualitySchema::string($data, 'truthCutoffUtc'));
        $msk = new \DateTimeZone('Europe/Moscow');
        if ('1 00:00:00.000000' !== $captureFrom->setTimezone($msk)->format('N H:i:s.u') || '1 00:00:00.000000' !== $captureTo->setTimezone($msk)->format('N H:i:s.u') || $captureTo <= $captureFrom || $captureTo->getTimestamp() - $captureFrom->getTimestamp() < 1209600 || $cutoff <= $captureTo) {
            throw new \InvalidArgumentException('Capture window needs at least two complete MSK ISO weeks and a later truth cutoff.');
        }
        $slots = [];
        foreach (NetQualitySchema::rows($data['slots'], 200) as $slot) {
            NetQualitySchema::keys($slot, ['requestId', 'scheduledAtUtc']);
            $id = NetQualitySchema::uuid(NetQualitySchema::string($slot, 'requestId'), true);
            $at = NetQualitySchema::utc(NetQualitySchema::string($slot, 'scheduledAtUtc'));
            if (isset($slots[$id]) || $at < $captureFrom || $at >= $captureTo || '12:00:00.000000' !== $at->setTimezone($msk)->format('H:i:s.u')) {
                throw new \InvalidArgumentException('Invalid or duplicate scheduled capture.');
            }
            $slots[$id] = ['requestId' => $id, 'scheduledAtUtc' => $at->format('Y-m-d\TH:i:s.u\Z')];
        }
        $scheduled = array_column(array_values($slots), 'scheduledAtUtc');
        sort($scheduled);
        $expected = [];
        for ($at = $captureFrom->modify('+12 hours'); $at < $captureTo; $at = $at->modify('+1 day')) {
            $expected[] = $at->format('Y-m-d\TH:i:s.u\Z');
            if (\count($expected) > 200) {
                throw new \OverflowException('Protocol exceeds capture reference limit.');
            }
        }
        if ($scheduled !== $expected) {
            throw new \InvalidArgumentException('Every daily capture slot must be registered.');
        }
        $this->slots = array_values($slots);
        $truths = [];
        foreach (NetQualitySchema::rows($data['truthSlots'], 200) as $slot) {
            NetQualitySchema::keys($slot, ['requestId', 'inputId', 'scheduledAtUtc']);
            $id = NetQualitySchema::uuid(NetQualitySchema::string($slot, 'requestId'), true);
            $input = NetQualitySchema::uuid(NetQualitySchema::string($slot, 'inputId'), true);
            $at = NetQualitySchema::utc(NetQualitySchema::string($slot, 'scheduledAtUtc'));
            if (isset($truths[$id]) || !isset($slots[$input]) || $at <= NetQualitySchema::utc($slots[$input]['scheduledAtUtc']) || $at > $cutoff) {
                throw new \InvalidArgumentException('Invalid scheduled truth observation.');
            }
            $truths[$id] = ['requestId' => $id, 'inputId' => $input, 'scheduledAtUtc' => $at->format('Y-m-d\TH:i:s.u\Z')];
        }
        if (\count(array_unique(array_column(array_values($truths), 'inputId'))) !== \count($slots)) {
            throw new \InvalidArgumentException('Every input needs a registered truth observation.');
        }
        $this->truthSlots = array_values($truths);
        $data['skus'] = $this->skus;
        $data['currencies'] = $this->currencies;
        $data['slots'] = $this->slots;
        $data['truthSlots'] = $this->truthSlots;
        $data['parameters'] = self::PARAMETERS;
        $this->data = $data;
    }

    public function assertCapture(string $companyId, string $accountId, string $requestId, string $planId, string $from, string $to): void
    {
        if ($companyId !== $this->companyId || $accountId !== $this->accountId || $planId !== $this->planId || $from !== $this->from || $to !== $this->to || !\in_array($requestId, array_column($this->slots, 'requestId'), true)) {
            throw new \DomainException('Capture is outside the registered quality protocol.');
        }
    }
}
