import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import type { BuyoutDays } from '../lib/buyoutParams'

export type BuyoutDailyResponse = components['schemas']['BuyoutDailyResponse']

export function buyoutDailyQueryKey(
  companyId: string,
  marketplaceSku: string,
  days: BuyoutDays,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'buyout-rate-daily', {
    days,
    marketplaceSku,
  })
}

export function buyoutDailyPath(
  marketplaceSku: string,
  days: BuyoutDays,
): string {
  return `/buyout-rate/${encodeURIComponent(marketplaceSku)}/daily?days=${days}`
}

export function useBuyoutDaily(
  companyId: string,
  marketplaceSku: string,
  days: BuyoutDays,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: buyoutDailyQueryKey(companyId, marketplaceSku, days),
    queryFn: () =>
      createCompanyApiClient(companyId).get<BuyoutDailyResponse>(
        buyoutDailyPath(marketplaceSku, days),
      ),
    enabled: options?.enabled ?? true,
  })
}
