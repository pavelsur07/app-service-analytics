import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import type { BuyoutDays } from '../lib/buyoutParams'

export type BuyoutRatesResponse =
  components['schemas']['BuyoutRateListResponse']

export interface BuyoutRatesParams {
  days: BuyoutDays
  limit: number
  cursor: string | null
}

export function buyoutRatesQueryKey(
  companyId: string,
  params: BuyoutRatesParams,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'buyout-rate', { ...params })
}

export function buyoutRatesPath(params: BuyoutRatesParams): string {
  const query = new URLSearchParams({
    days: String(params.days),
    limit: String(params.limit),
  })

  if (params.cursor !== null) {
    query.set('cursor', params.cursor)
  }

  return `/buyout-rate?${query.toString()}`
}

export function useBuyoutRates(
  companyId: string,
  params: BuyoutRatesParams,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: buyoutRatesQueryKey(companyId, params),
    queryFn: () =>
      createCompanyApiClient(companyId).get<BuyoutRatesResponse>(
        buyoutRatesPath(params),
      ),
    enabled: options?.enabled ?? true,
  })
}
