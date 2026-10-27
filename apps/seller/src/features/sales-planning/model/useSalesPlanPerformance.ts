import { queryOptions, useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

export type PerformanceParams = Pick<
  components['schemas']['SalesPlanPerformanceResponse'],
  'from' | 'to' | 'groupBy'
> & { cursor: string | null; limit: number }

export function performanceQueryOptions(
  companyId: string,
  planId: string,
  params: PerformanceParams,
  headerVersion: number,
) {
  const search = new URLSearchParams({
    from: params.from,
    to: params.to,
    groupBy: params.groupBy,
    limit: String(params.limit),
  })
  if (params.cursor !== null) search.set('cursor', params.cursor)
  return queryOptions({
    queryKey: companyQueryKey(companyId, 'sales-planning', 'performance', {
      planId,
      ...params,
      headerVersion,
    }),
    enabled: companyId !== '' && planId !== '',
    retry: false,
    queryFn: () => {
      if (companyId === '' || planId === '')
        throw new Error('Нужны компания и план.')
      return createCompanyApiClient(companyId).get<
        components['schemas']['SalesPlanPerformanceResponse']
      >(
        `/sales-plans/${encodeURIComponent(planId)}/performance?${search.toString()}`,
      )
    },
  })
}

export function useSalesPlanPerformance(
  companyId: string,
  planId: string,
  params: PerformanceParams,
  headerVersion: number,
  enabled: boolean,
) {
  return useQuery({
    ...performanceQueryOptions(companyId, planId, params, headerVersion),
    enabled: enabled && companyId !== '' && planId !== '',
  })
}
