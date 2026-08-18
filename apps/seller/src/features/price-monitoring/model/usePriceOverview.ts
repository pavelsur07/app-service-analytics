import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type PriceOverviewListResponse =
  components['schemas']['PriceOverviewListResponse']

export type PriceOverviewItem =
  components['schemas']['PriceOverviewItemResponse']

export function priceOverviewQueryKey(companyId: string): readonly unknown[] {
  return companyQueryKey(companyId, 'price-monitoring', 'overview')
}

export function usePriceOverview(companyId: string) {
  return useQuery({
    queryKey: priceOverviewQueryKey(companyId),
    queryFn: () =>
      createCompanyApiClient(companyId).get<PriceOverviewListResponse>(
        '/prices',
      ),
  })
}
