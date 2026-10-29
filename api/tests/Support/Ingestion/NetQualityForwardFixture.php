<?php

declare(strict_types=1);

namespace App\Tests\Support\Ingestion;

use App\Ingestion\Application\NetForecast as Net;
use App\Ingestion\Domain\NetForwardArtifactKind;
use App\Ingestion\Infrastructure\Config\NetForecastCaptureEpochs;
use App\Ingestion\Infrastructure\Persistence\DbalNetForecastArtifactRepository;
use App\Ingestion\Infrastructure\Query\Buyout\BuyoutMaturityQuery;
use App\Ingestion\Infrastructure\Query\NetForecast as Query;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortCoverageQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortMetricsQuery;
use App\Ingestion\Infrastructure\Query\SalesCohort\SalesCohortSeriesQuery;
use App\Shared\Domain\ValueObject\Money;
use App\Tests\Support\Builder\NetQualityProtocolBuilder;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** Real B15 capture over Builder/normalizer fixtures, never hand-built forecast bytes. */
final readonly class NetQualityForwardFixture
{
    public NetQualityArtifactFixture $artifacts;
    public NetTargetFixture $targets;
    public Net\NetQualityProtocol $protocol;
    public Net\NetQualityCaptureContext $context;
    public Net\NetQualityArtifactRef $input;
    public Net\NetQualityArtifactRef $protocolRef;

    /** @param list<int> $truthLags */
    public function __construct(Connection $db, Net\AggregateNetForecastAction $aggregate, array $truthLags = [7, 70])
    {
        if ([] === $truthLags) {
            throw new \InvalidArgumentException('Fixture needs registered truth slots.');
        }
        $training = new NetTrainingFixture($db);
        $at = $training->readAt->modify('+12 hours');
        $this->targets = $targets = new NetTargetFixture($db, $at, $training->company, $training->account);
        for ($i = 0; $i < 30; ++$i) {
            $training->posting('TRAIN-D-'.$i, 'SKU', 10);
            $training->posting('TRAIN-C-'.$i, 'SKU', 10, 'cancelled');
            $training->returned('TRAIN-D-'.$i, 'SKU', 1, $training->terminal->modify('+10 days'));
        }
        $training->returnCoverage();
        $day = $at->modify('-2 days')->setTime(0, 0);
        $origin = $at->modify('-1 day');
        $targets->delivered('TARGET', 'SKU', 10, $origin, day: $day, amount: Money::ofMinor(7, 'RUB'));
        $targets->daily($origin);
        $targets->currentReturns();
        $start = $at->setTimezone(new \DateTimeZone('Europe/Moscow'))->modify('monday this week')->setTime(0, 0)->setTimezone(new \DateTimeZone('UTC'));
        $slots = $truths = [];
        $selected = null;
        for ($i = 0; $i < 14; ++$i) {
            $id = Uuid::v7()->toRfc4122();
            $scheduled = $start->modify('+'.$i.' days +12 hours');
            $slots[] = ['requestId' => $id, 'scheduledAtUtc' => $scheduled->format('Y-m-d\TH:i:s.u\Z')];
            foreach ($truthLags as $lag) {
                $truths[] = ['requestId' => Uuid::v7()->toRfc4122(), 'inputId' => $id, 'scheduledAtUtc' => $scheduled->modify('+'.$lag.' days')->format('Y-m-d\TH:i:s.u\Z')];
            }
            if ($scheduled->format('Y-m-d') === $at->format('Y-m-d')) {
                $selected = $id;
            }
        }
        if (null === $selected) {
            throw new \LogicException('Fixture readAt must have a scheduled daily slot.');
        }
        $planId = Uuid::v7()->toRfc4122();
        $from = $day->modify('-1 day')->format('Y-m-d');
        $to = $day->modify('+1 day')->format('Y-m-d');
        $this->protocol = NetQualityProtocolBuilder::aProtocol()->with('companyId', $training->company->toRfc4122())->with('accountId', $training->account->toRfc4122())->with('planId', $planId)->with('baselineId', $planId)->with('epochId', $targets->epoch->id)->with('epochStartUtc', $targets->epoch->start->format('Y-m-d\TH:i:s.u\Z'))->with('from', $from)->with('to', $to)->with('calibrationFrom', $from)->with('calibrationTo', $from)->with('holdoutFrom', $day->format('Y-m-d'))->with('holdoutTo', $to)->with('captureFromUtc', $start->format('Y-m-d\TH:i:s.u\Z'))->with('captureToUtc', $start->modify('+14 days')->format('Y-m-d\TH:i:s.u\Z'))->with('truthCutoffUtc', $start->modify('+'.(max($truthLags) + 15).' days')->format('Y-m-d\TH:i:s.u\Z'))->with('slots', $slots)->with('truthSlots', $truths)->build();
        $this->artifacts = $artifacts = new NetQualityArtifactFixture($db, min($start->modify('-1 day'), $day->modify('-1 day')));
        $company = $this->protocol->companyId;
        $account = $this->protocol->accountId;
        $this->protocolRef = (new Net\FreezeNetQualityProtocolAction($artifacts->writer, $artifacts->reader, new DbalNetForecastArtifactRepository($db), $artifacts->clock, '123-aaaaaaa', str_repeat('a', 40)))($company, $account, $this->protocol);
        $request = new Net\NetForwardCaptureRequest($selected, $planId, $from, $to, qualityProtocolId: $this->protocolRef->requestId, qualityProtocolHash: $this->protocolRef->sha256);
        $this->context = (new Net\NetQualityProtocolReader($artifacts->reader))->forCapture($company, $account, $request, $this->protocolRef, new Net\NetQualityBudget());
        $epoch = $targets->epoch;
        $epochs = new NetForecastCaptureEpochs(json_encode([['epochId' => $epoch->id, 'captureVersion' => $epoch->captureVersion, 'startUtc' => $epoch->start->format('Y-m-d\TH:i:s\Z'), 'endUtc' => null, 'cutoverRelease' => $epoch->cutoverRelease]], \JSON_THROW_ON_ERROR));
        $readTraining = new Net\ReadNetTrainingAction($db, new Query\GrossTrainingAggregateQuery($db), new Query\ReturnTrainingAggregateQuery($db), $epochs, new MockClock($at));
        $prepare = new Net\PrepareNetForecastCaptureAction($db, new Net\ReadNetForecastInputsAction($db, $readTraining, new Query\NetTargetQuery($db)), $aggregate, new Net\ReadNetForwardEvidenceAction($db, new Query\NetForwardEvidenceQuery($db)), new Query\NetForwardBaselineQuery($db), new BuyoutMaturityQuery($db), new SalesCohortSeriesQuery($db, new SalesCohortMetricsQuery($db), new SalesCohortCoverageQuery($db)), $artifacts->codec, new Net\NetForwardInputCodec(), '123-aaaaaaa', str_repeat('a', 40));
        $plan = new Net\NetForwardPlanContext($planId, 1, 1, $planId, 1, 'retained_sales', 'RUB', $from, ['SKU'], [['sku' => 'SKU', 'points' => []]]);
        $prepared = (new Net\NetTrainingSnapshot($db))->read(fn () => $prepare($company, $account, $request, $plan, (int) (hrtime(true) + 30000000000), $this->context));
        $verified = ($artifacts->publisher)($company, $account, $prepared);
        $this->input = new Net\NetQualityArtifactRef(NetForwardArtifactKind::Input, $selected, $verified->publication->root->sha256);
    }

    public function cleanup(): void
    {
        $this->artifacts->cleanup($this->protocol->companyId, $this->protocol->accountId);
        $this->targets->cleanup();
    }

    public function truth(int $lagDays): Net\NetQualityArtifactRef
    {
        $at = $this->targets->readAt->modify('+'.$lagDays.' days');
        $requestId = null;
        foreach ($this->protocol->truthSlots as $slot) {
            if ($slot['inputId'] === $this->input->requestId && substr($slot['scheduledAtUtc'], 0, 10) === $at->format('Y-m-d')) {
                $requestId = $slot['requestId'];
            }
        }
        if (null === $requestId) {
            throw new \LogicException('Truth must use a pre-registered fixture slot.');
        }
        $future = new NetTargetFixture($this->artifacts->db, $at, $this->targets->company, $this->targets->account);
        $future->daily($this->targets->readAt->modify('-1 day'), deep: true);
        $future->currentReturns();
        $epoch = $this->targets->epoch;
        $epochs = new NetForecastCaptureEpochs(json_encode([['epochId' => $epoch->id, 'captureVersion' => $epoch->captureVersion, 'startUtc' => $epoch->start->format('Y-m-d\TH:i:s\Z'), 'endUtc' => null, 'cutoverRelease' => $epoch->cutoverRelease]], \JSON_THROW_ON_ERROR));
        $db = $this->artifacts->db;
        $capture = new Net\CaptureNetForecastTruthAction($db, new DbalNetForecastArtifactRepository($db), $this->artifacts->reader, $this->artifacts->publisher, $this->artifacts->codec, new Net\NetForwardInputCodec(), $epochs, new Query\NetTargetQuery($db), new Net\ReadNetForwardEvidenceAction($db, new Query\NetForwardEvidenceQuery($db)), new MockClock($at), softwareRelease: '123-aaaaaaa');
        $truth = $capture($this->protocol->companyId, $this->protocol->accountId, $this->input->requestId, $this->input->sha256, $requestId);

        return new Net\NetQualityArtifactRef(NetForwardArtifactKind::Truth, $requestId, $truth->publication->root->sha256);
    }

    public function selection(): Net\NetQualityArtifactRef
    {
        $inventory = $this->inventory('capture', [$this->input]);
        $protocols = new Net\NetQualityProtocolReader($this->artifacts->reader);
        $select = new Net\SelectNetQualityObservationsAction($this->artifacts->reader, new Net\NetQualityInventoryReader($this->artifacts->reader), $protocols, new Net\NetQualityInputReader($this->artifacts->reader, new Net\NetForwardInputCodec()), new Net\NetQualitySelection(), $this->artifacts->writer);

        return $select($this->protocol->companyId, $this->protocol->accountId, new Net\NetQualitySelectionRequest(Uuid::v7()->toRfc4122(), $this->protocolRef, $inventory));
    }

    /** @param list<Net\NetQualityArtifactRef> $truths */
    public function truthInventory(array $truths): Net\NetQualityTruthInventory
    {
        $ref = $this->inventory('truth', $truths);
        $inventory = (new Net\NetQualityInventoryReader($this->artifacts->reader))->read($this->protocol->companyId, $this->protocol->accountId, $ref, new Net\NetQualityBudget());
        if (!$inventory instanceof Net\NetQualityTruthInventory) {
            throw new \LogicException('Expected a fixture truth inventory.');
        }

        return $inventory;
    }

    /** @param list<Net\NetQualityArtifactRef> $refs */
    private function inventory(string $subtype, array $refs): Net\NetQualityArtifactRef
    {
        $byId = [];
        foreach ($refs as $ref) {
            $byId[$ref->requestId] = $ref;
        }
        $slots = [];
        foreach ('capture' === $subtype ? $this->protocol->slots : $this->protocol->truthSlots as $slot) {
            $ref = $byId[$slot['requestId']] ?? null;
            $slots[] = ['requestId' => $slot['requestId'], 'status' => null !== $ref ? 'success' : 'missing', 'artifact' => $ref?->toArray(), 'reason' => null !== $ref ? null : 'not_captured'];
        }
        $cutoffKey = 'capture' === $subtype ? 'captureToUtc' : 'truthCutoffUtc';
        $cutoff = Net\NetQualitySchema::utc(Net\NetQualitySchema::string($this->protocol->data, $cutoffKey));
        $delta = $cutoff->getTimestamp() - $this->artifacts->clock->now()->getTimestamp() + 1;
        if ($delta > 0) {
            $this->artifacts->clock->modify('+'.$delta.' seconds');
        }
        $sealer = new Net\SealNetQualityInventoryAction($this->artifacts->writer, $this->artifacts->reader, new Net\NetQualityProtocolReader($this->artifacts->reader), new DbalNetForecastArtifactRepository($this->artifacts->db), $this->artifacts->clock);

        return $sealer($this->protocol->companyId, $this->protocol->accountId, new Net\NetQualityInventoryRequest(['requestId' => Uuid::v7()->toRfc4122(), 'protocol' => $this->protocolRef->toArray(), 'subtype' => $subtype, 'cutoffUtc' => $this->protocol->data[$cutoffKey], 'slots' => $slots]));
    }
}
