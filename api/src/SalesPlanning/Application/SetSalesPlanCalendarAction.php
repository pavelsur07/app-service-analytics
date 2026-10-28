<?php

declare(strict_types=1);

namespace App\SalesPlanning\Application;

use App\Identity\Application\Facade\IdentityFacade;
use App\SalesPlanning\Domain\SalesPlanAuditAction;
use App\SalesPlanning\Domain\SalesPlanCalendarEntry;
use App\SalesPlanning\Domain\SalesPlanCalendarRepository;
use App\SalesPlanning\Domain\SalesPlanRepository;
use App\SalesPlanning\Domain\SalesPlanStatus;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Component\Uid\Uuid;

final readonly class SetSalesPlanCalendarAction
{
    public function __construct(private SalesPlanRepository $plans, private SalesPlanCalendarRepository $calendar, private IdentityFacade $identity)
    {
    }

    /** @param list<SalesPlanCalendarInput> $inputs */
    public function __invoke(string $companyId, string $planId, int $expectedVersion, array $inputs, string $actorUserId): SetSalesPlanCalendarResult
    {
        $plan = $this->plans->get($companyId, Uuid::fromString($planId));
        if (null === $plan) {
            return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::NotFound);
        }
        if ($plan->version() !== $expectedVersion) {
            return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::VersionConflict);
        }
        if (SalesPlanStatus::Draft !== $plan->status()) {
            return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::NotEditable);
        }
        if (!array_is_list($inputs) || \count($inputs) > SalesPlanCalendarEntry::MaxEntries) {
            return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::InvalidEntries);
        }
        $existing = [];
        foreach ($this->calendar->entriesForUpdate($companyId, $plan->id()) as $entry) {
            $existing[$entry->id()->toRfc4122()] = $entry;
        }
        $ids = [];
        $events = [];
        $skus = [];
        foreach ($inputs as $input) {
            try {
                $input->event->assertWithin($plan);
            } catch (\InvalidArgumentException) {
                return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::InvalidEntries);
            }
            if (null !== $input->id) {
                $id = $input->id->toRfc4122();
                if (!isset($existing[$id]) || isset($ids[$id])) {
                    return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::InvalidEntries);
                }
                $ids[$id] = true;
            }
            $key = json_encode([$input->event->kind->value, $input->event->marketplaceSku, $input->event->dateFrom->format('Y-m-d'), $input->event->dateTo->format('Y-m-d')], \JSON_THROW_ON_ERROR);
            if (isset($events[$key])) {
                return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::InvalidEntries);
            }
            $events[$key] = true;
            if (null !== $input->event->marketplaceSku) {
                $skus[] = $input->event->marketplaceSku;
            }
        }
        if (!$this->calendar->allSkusExist($companyId, $plan->id(), array_values(array_unique($skus)))) {
            return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::UnknownSku);
        }
        try {
            return $this->plans->transactional(function () use ($companyId, $planId, $actorUserId, $plan, $existing, $ids, $inputs): SetSalesPlanCalendarResult {
                // Lock/version boundary first. No calendar entity has been changed or staged yet.
                $plan->recordCalendarChanged(new \DateTimeImmutable());
                $this->plans->saveMetadata($plan);
                foreach ($inputs as $input) {
                    if (null === $input->id) {
                        $entry = SalesPlanCalendarEntry::create($plan, $input->event);
                        $this->calendar->add($entry);
                        $this->audit($companyId, $actorUserId, SalesPlanAuditAction::CalendarCreated, $entry, null, self::describe($planId, $entry));
                    } else {
                        $entry = $existing[$input->id->toRfc4122()];
                        $before = self::describe($planId, $entry);
                        $entry->change($plan, $input->event);
                        $after = self::describe($planId, $entry);
                        if ($before !== $after) {
                            $this->audit($companyId, $actorUserId, SalesPlanAuditAction::CalendarChanged, $entry, $before, $after);
                        }
                    }
                }
                foreach ($existing as $id => $entry) {
                    if (!isset($ids[$id])) {
                        $this->audit($companyId, $actorUserId, SalesPlanAuditAction::CalendarRemoved, $entry, self::describe($planId, $entry), null);
                        $this->calendar->remove($entry);
                    }
                }

                // Outer ORM transaction flushes calendar+audit together before commit.
                return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::Saved, $plan->version());
            });
        } catch (OptimisticLockException) {
            return new SetSalesPlanCalendarResult(SetSalesPlanCalendarOutcome::VersionConflict);
        }
    }

    public static function describe(string $planId, SalesPlanCalendarEntry $entry): string
    {
        $event = $entry->event();

        return json_encode(['planId' => $planId, 'id' => $entry->id()->toRfc4122(), 'marketplaceSku' => $event->marketplaceSku, 'kind' => $event->kind->value, 'dateFrom' => $event->dateFrom->format('Y-m-d'), 'dateTo' => $event->dateTo->format('Y-m-d')], \JSON_THROW_ON_ERROR);
    }

    private function audit(string $companyId, string $actor, string $action, SalesPlanCalendarEntry $entry, ?string $before, ?string $after): void
    {
        $this->identity->recordAuditEntry($companyId, $actor, $action, $entry->id()->toRfc4122(), $before, $after);
    }
}
