import { useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import type { SortDirection, SortKey } from '../lib/tableParams'

type UnitEconomicsResponse = components['schemas']['UnitEconomicsResponse']

export interface UnitEconomicsParams {
  days: number
  limit: number
  sort: SortKey
  direction: SortDirection
  cursor: string | null
}

export function unitEconomicsQueryKey(
  companyId: string,
  params: UnitEconomicsParams,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'unit-economics', {
    ...params,
  })
}

/**
 * Строка запроса отдельной чистой функцией, а не склейкой внутри
 * queryFn: опечатка в ней молчит — бэкенд подставит умолчание, и экран
 * просто окажется отсортирован не так, как показывает стрелка.
 * Проверить это можно только здесь: компонентных тестов у приложения нет.
 */
export function unitEconomicsPath(params: UnitEconomicsParams): string {
  const query = new URLSearchParams({
    days: String(params.days),
    limit: String(params.limit),
    sort: params.sort,
    direction: params.direction,
  })

  if (params.cursor !== null) {
    query.set('cursor', params.cursor)
  }

  return `/unit-economics?${query.toString()}`
}

export function useUnitEconomics(
  companyId: string,
  params: UnitEconomicsParams,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: unitEconomicsQueryKey(companyId, params),
    queryFn: () =>
      createCompanyApiClient(companyId).get<UnitEconomicsResponse>(
        unitEconomicsPath(params),
      ),
    enabled: options?.enabled ?? true,
  })
}
