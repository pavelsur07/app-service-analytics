import { useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type UnitEconomicsResponse = components['schemas']['UnitEconomicsResponse']

export function unitEconomicsQueryKey(
  companyId: string,
  days: number,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'unit-economics', { days })
}

export function useUnitEconomics(
  companyId: string,
  days: number,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: unitEconomicsQueryKey(companyId, days),
    queryFn: () =>
      createCompanyApiClient(companyId).get<UnitEconomicsResponse>(
        `/unit-economics?days=${days}`,
      ),
    enabled: options?.enabled ?? true,
  })
}
