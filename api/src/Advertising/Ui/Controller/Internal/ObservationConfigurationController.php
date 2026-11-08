<?php

declare(strict_types=1);

namespace App\Advertising\Ui\Controller\Internal;

use App\Advertising\Infrastructure\Query\ServiceObservationPoliciesQuery;
use App\Shared\Ui\QueryParameter;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/api/internal/ozon-ads/policies', methods: ['GET'])]
#[IsGranted('ROLE_INTERNAL_SERVICE')]
final readonly class ObservationConfigurationController
{
    public function __construct(private bool $adsEnabled, private ServiceObservationPoliciesQuery $policies, private ClockInterface $clock)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $limit = QueryParameter::int($request, 'limit', 100);
        $cursor = $request->query->get('cursor');
        if (null === $limit || $limit < 1 || $limit > 100 || (null !== $cursor && (!\is_string($cursor) || !Uuid::isValid($cursor)))) {
            return new JsonResponse(['code' => 'invalid_page'], 422);
        }
        $raw = $this->adsEnabled ? array_map(ServiceObservationPoliciesQuery::mapRow(...), $this->policies->page($cursor, $limit)->executeQuery()->fetchAllAssociative()) : [];
        $hasNext = \count($raw) > $limit;
        $raw = \array_slice($raw, 0, $limit);
        $until = $this->clock->now()->modify('+120 seconds')->format(\DATE_RFC3339);
        $items = [];
        foreach ($raw as $row) {
            $items[] = ['company_id' => $row->companyId, 'account_id' => $row->accountId, 'policy_id' => $row->id, 'campaign_id' => $row->campaignId, 'version' => $row->version, 'mode' => 'observe', 'enabled' => $row->enabled, 'daily_limit' => ['amount_minor' => $row->dailyLimit->minorAmount(), 'currency' => $row->dailyLimit->currency()], 'timezone' => 'Europe/Moscow', 'start' => $row->start, 'stop' => $row->stop, 'poll_seconds' => $row->pollSeconds, 'permission_until' => $until];
        }

        return new JsonResponse(['schema_version' => '1', 'policies' => $items, 'next_cursor' => $hasNext ? $raw[\count($raw) - 1]->id : null], 200, ['Cache-Control' => 'no-store']);
    }
}
