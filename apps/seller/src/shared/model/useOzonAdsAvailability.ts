import { useQuery } from '@tanstack/react-query'

import type { paths } from '../../api/schema'
import { createCompanyApiClient } from '../../api/companyClient'
import { companyQueryKey } from '../lib/companyQueryKey'

type Availability =
  paths['/api/companies/{companyId}/ozon-ads/availability']['get']['responses'][200]['content']['application/json']

export function useOzonAdsAvailability(companyId: string) {
  return useQuery({
    queryKey: companyQueryKey(companyId, 'ozon-ads', 'availability'),
    queryFn: () =>
      createCompanyApiClient(companyId).get<Availability>(
        '/ozon-ads/availability',
      ),
    staleTime: 60_000,
    retry: false,
  })
}
