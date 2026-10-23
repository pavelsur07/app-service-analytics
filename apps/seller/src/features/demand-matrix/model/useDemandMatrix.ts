import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

export type DemandMatrixReportResponse =
  components['schemas']['DemandMatrixReportResponse']

export interface DemandMatrixParams {
  search: string
  limit: number
  cursor: string | null
}

export function demandMatrixQueryKey(
  companyId: string,
  params: DemandMatrixParams,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'demand-matrix', {
    ...params,
  })
}

export function demandMatrixPath(params: DemandMatrixParams): string {
  const query = new URLSearchParams({ limit: String(params.limit) })
  if (params.search !== '') {
    query.set('search', params.search)
  }
  if (params.cursor !== null) {
    query.set('cursor', params.cursor)
  }

  return `/demand-matrix?${query.toString()}`
}

export function useDemandMatrix(
  companyId: string,
  params: DemandMatrixParams,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: demandMatrixQueryKey(companyId, params),
    queryFn: () =>
      createCompanyApiClient(companyId).get<DemandMatrixReportResponse>(
        demandMatrixPath(params),
      ),
    enabled: options?.enabled ?? true,
  })
}
