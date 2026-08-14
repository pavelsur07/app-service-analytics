import { useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type ConnectionsResponse = components['schemas']['ConnectionsResponse']

export function useConnections(
  companyId: string,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: companyQueryKey(companyId, 'identity', 'connections'),
    queryFn: () =>
      createCompanyApiClient(companyId).get<ConnectionsResponse>(
        '/connections',
      ),
    enabled: options?.enabled ?? true,
  })
}
