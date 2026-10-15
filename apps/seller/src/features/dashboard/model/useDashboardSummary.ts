import { type Query, useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import type { DateRange } from '../lib/period'

export type DashboardSummaryResponse =
  components['schemas']['DashboardSummaryResponse']

export interface DashboardSummaryParams {
  current: DateRange
  comparison: DateRange
}

export function dashboardSummaryQueryKey(
  companyId: string,
  params: DashboardSummaryParams,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'dashboard', {
    from: params.current.from,
    to: params.current.to,
    compareFrom: params.comparison.from,
    compareTo: params.comparison.to,
  })
}

export function dashboardSummaryPath(params: DashboardSummaryParams): string {
  const query = new URLSearchParams({
    from: params.current.from,
    to: params.current.to,
    compareFrom: params.comparison.from,
    compareTo: params.comparison.to,
  })

  return `/dashboard/summary?${query.toString()}`
}

/**
 * Прежние цифры на время загрузки новых — только той же компании.
 * Смена периода не сбрасывает виджеты в скелетон, а смена компании
 * не показывает чужие цифры ни на мгновение (CLAUDE.md §7): ключ
 * предыдущего запроса несёт companyId вторым элементом.
 */
export function previousOfSameCompany(companyId: string) {
  return (
    previous: DashboardSummaryResponse | undefined,
    previousQuery: Pick<Query, 'queryKey'> | undefined,
  ): DashboardSummaryResponse | undefined =>
    previousQuery?.queryKey[1] === companyId ? previous : undefined
}

export function useDashboardSummary(
  companyId: string,
  params: DashboardSummaryParams,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: dashboardSummaryQueryKey(companyId, params),
    queryFn: () =>
      createCompanyApiClient(companyId).get<DashboardSummaryResponse>(
        dashboardSummaryPath(params),
      ),
    enabled: options?.enabled ?? true,
    placeholderData: previousOfSameCompany(companyId),
  })
}
