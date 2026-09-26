import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import type { StockPlacementView } from '../lib/stockPlacementParams'

export type StockPlacementReportResponse =
  components['schemas']['StockPlacementReportResponse']

export interface StockPlacementParams extends StockPlacementView {
  limit: number
  cursor: string | null
}

export function stockPlacementQueryKey(
  companyId: string,
  params: StockPlacementParams,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'stock-placement', {
    ...params,
  })
}

export function stockPlacementPath(params: StockPlacementParams): string {
  const query = new URLSearchParams({
    target_days: String(params.targetDays),
    lead_days: String(params.leadDays),
    limit: String(params.limit),
  })

  if (params.status !== null) {
    query.set('status', params.status)
  }
  if (params.cursor !== null) {
    query.set('cursor', params.cursor)
  }

  return `/stock-placement?${query.toString()}`
}

export function useStockPlacement(
  companyId: string,
  params: StockPlacementParams,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: stockPlacementQueryKey(companyId, params),
    queryFn: () =>
      createCompanyApiClient(companyId).get<StockPlacementReportResponse>(
        stockPlacementPath(params),
      ),
    enabled: options?.enabled ?? true,
  })
}
