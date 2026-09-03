import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

type MonthlyClicksResponse = components['schemas']['MonthlyClicksResponse']

export function monthlyClicksQueryKey(linkId: string | null, month: string) {
  return adminQueryKey('links', 'clicks', linkId, month)
}

export function useMonthlyClicks(linkId: string | null, month: string) {
  return useQuery({
    queryKey: monthlyClicksQueryKey(linkId, month),
    queryFn: () => {
      if (linkId === null) {
        throw new Error('A link must be selected before loading clicks.')
      }

      return apiGet<MonthlyClicksResponse>(
        `/api/admin/links/${encodeURIComponent(linkId)}/clicks?month=${encodeURIComponent(month)}`,
      )
    },
    enabled: linkId !== null,
  })
}
