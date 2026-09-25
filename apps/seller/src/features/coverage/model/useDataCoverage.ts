import { useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type DataCoverageResponse = components['schemas']['DataCoverageResponse']

export function dataCoverageQueryKey(
  companyId: string,
  accountId: string,
  month: string,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'coverage', {
    accountId,
    month,
  })
}

export function useDataCoverage(
  companyId: string,
  accountId: string | null,
  month: string,
) {
  return useQuery({
    queryKey: dataCoverageQueryKey(companyId, accountId ?? '', month),
    queryFn: () =>
      createCompanyApiClient(companyId).get<DataCoverageResponse>(
        `/connections/${encodeURIComponent(accountId ?? '')}/coverage?month=${encodeURIComponent(month)}`,
      ),
    enabled: accountId !== null,
  })
}
