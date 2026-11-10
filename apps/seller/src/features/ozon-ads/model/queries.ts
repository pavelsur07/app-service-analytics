import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import type { PolicyList, EventList, CampaignCatalog } from './types'

export function useCampaignCatalog(
  companyId: string,
  accountId: string,
  cursor: string | null,
  includeArchived = false,
  enabled = true,
) {
  const params = new URLSearchParams({
    accountId,
    limit: '50',
    includeArchived: String(includeArchived),
  })
  if (cursor !== null) params.set('cursor', cursor)
  const query = params.toString()
  return useQuery({
    queryKey: adsKey(companyId, 'catalog', { params: query }),
    queryFn: () =>
      createCompanyApiClient(companyId).get<CampaignCatalog>(
        `/ozon-advertising/campaigns?${query}`,
      ),
    enabled: enabled && accountId !== '',
  })
}

export function adsKey(
  companyId: string,
  entity: string,
  params?: Record<string, unknown>,
) {
  return companyQueryKey(companyId, 'ozon-ads', entity, params)
}
export function listParams(
  accountId: string,
  cursor: string | null,
  enabled = 'all',
) {
  const params = new URLSearchParams({ limit: '20' })
  if (accountId !== '') params.set('accountId', accountId)
  if (cursor !== null) params.set('cursor', cursor)
  if (enabled !== 'all') params.set('enabled', enabled)
  return params.toString()
}
export function usePolicies(
  companyId: string,
  accountId: string,
  cursor: string | null,
  status: string,
  enabled: boolean,
) {
  const params = listParams(accountId, cursor, status)
  return useQuery({
    queryKey: adsKey(companyId, 'policies', { params }),
    queryFn: () =>
      createCompanyApiClient(companyId).get<PolicyList>(
        `/ozon-ads/policies?${params}`,
      ),
    enabled,
    retry: false,
    refetchInterval: enabled ? 30_000 : false,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false,
  })
}
export function useEvents(
  companyId: string,
  accountId: string,
  cursor: string | null,
  enabled: boolean,
) {
  const params = listParams(accountId, cursor)
  return useQuery({
    queryKey: adsKey(companyId, 'events', { params }),
    queryFn: () =>
      createCompanyApiClient(companyId).get<EventList>(
        `/ozon-ads/events?${params}`,
      ),
    enabled,
    retry: false,
    refetchInterval: enabled ? 30_000 : false,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: false,
  })
}
