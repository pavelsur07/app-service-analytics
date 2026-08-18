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

/**
 * Обход артикулов идёт раз в полчаса, но экран обновляется чаще
 * и по своей причине: без обновления «снято только что» застывает
 * на открытой вкладке навсегда, и остановившийся сбор выглядит как
 * свежие данные. Ровно тот отказ, ради видимости которого столбец
 * с возрастом и заведён.
 */
const REFETCH_MS = 60_000

export function usePriceOverview(companyId: string) {
  return useQuery({
    queryKey: priceOverviewQueryKey(companyId),
    queryFn: () =>
      createCompanyApiClient(companyId).get<PriceOverviewListResponse>(
        '/prices',
      ),
    refetchInterval: REFETCH_MS,
  })
}
