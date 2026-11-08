import { queryOptions, useQuery } from '@tanstack/react-query'
import { apiGet } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

export type ClientAccountDetail =
  components['schemas']['AdminCompanyDetailResponse']

export function clientAccountQueryOptions(companyId: string) {
  return queryOptions({
    queryKey: adminQueryKey('accounts', 'detail', companyId),
    queryFn: () =>
      apiGet<ClientAccountDetail>(
        `/api/admin/companies/${encodeURIComponent(companyId)}`,
      ),
  })
}

export function useClientAccount(companyId: string) {
  return useQuery(clientAccountQueryOptions(companyId))
}
