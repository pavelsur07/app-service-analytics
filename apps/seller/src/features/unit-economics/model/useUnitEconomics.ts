import { useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type UnitEconomicsResponse = components['schemas']['UnitEconomicsResponse']

export function unitEconomicsQueryKey(
  companyId: string,
  days: number,
  cursor: string | null,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'unit-economics', {
    days,
    cursor,
  })
}

export function useUnitEconomics(
  companyId: string,
  days: number,
  cursor: string | null,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: unitEconomicsQueryKey(companyId, days, cursor),
    queryFn: () => {
      const page =
        cursor === null ? '' : `&cursor=${encodeURIComponent(cursor)}`

      return createCompanyApiClient(companyId).get<UnitEconomicsResponse>(
        `/unit-economics?days=${days}${page}`,
      )
    },
    enabled: options?.enabled ?? true,
  })
}
