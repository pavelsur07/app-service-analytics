import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

export type ShortLink = components['schemas']['ShortLinkResponse']
type ShortLinkListResponse = components['schemas']['ShortLinkListResponse']

export function useLinks(page: number) {
  return useQuery({
    queryKey: adminQueryKey('links', { page }),
    queryFn: () =>
      apiGet<ShortLinkListResponse>(
        `/api/admin/links?page=${encodeURIComponent(String(page))}`,
      ),
  })
}
