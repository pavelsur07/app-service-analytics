import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

export type SkuForecastFactResponse =
  components['schemas']['SkuForecastFactResponse']
export type SkuForecastFactSkuSearchResponse =
  components['schemas']['SkuForecastFactSkuSearchResponse']

export function skuForecastFactQueryKey(
  companyId: string,
  sku: string,
  month: string,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'sku-forecast-fact', {
    sku,
    month,
  })
}

export function skuForecastFactPath(sku: string, month: string): string {
  return `/sku-forecast-fact?${new URLSearchParams({ sku, month })}`
}

export function useSkuForecastFact(
  companyId: string,
  sku: string,
  month: string,
) {
  return useQuery({
    queryKey: skuForecastFactQueryKey(companyId, sku, month),
    queryFn: () =>
      createCompanyApiClient(companyId).get<SkuForecastFactResponse>(
        skuForecastFactPath(sku, month),
      ),
    enabled: companyId !== '' && sku !== '',
  })
}

export function skuForecastFactSkusQueryKey(
  companyId: string,
  q: string,
  cursor: string | null,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'sku-forecast-fact-skus', {
    q,
    cursor,
  })
}

export function skuForecastFactSkusPath(
  q: string,
  limit: number,
  cursor: string | null,
): string {
  const search = new URLSearchParams({ q, limit: String(limit) })
  if (cursor !== null) search.set('cursor', cursor)
  return `/sku-forecast-fact/skus?${search}`
}

export function useSkuForecastFactSkus(
  companyId: string,
  q: string,
  limit: number,
  cursor: string | null,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: skuForecastFactSkusQueryKey(companyId, q, cursor),
    queryFn: () =>
      createCompanyApiClient(companyId).get<SkuForecastFactSkuSearchResponse>(
        skuForecastFactSkusPath(q, limit, cursor),
      ),
    enabled: companyId !== '' && (options?.enabled ?? true),
  })
}
