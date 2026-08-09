import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../../api/client'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type SalesFactListResponse = components['schemas']['SalesFactListResponse']

// Один узкий случай — список без сортировки/фильтров (пакет 6 их не
// отдаёт), курсор берёт компонент из состояния экрана.
export function useSalesFacts(
  companyId: string,
  cursor: string | null,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: companyQueryKey(companyId, 'ingestion', 'sales-facts', {
      cursor,
    }),
    queryFn: () => {
      const query =
        cursor === null ? '' : `?cursor=${encodeURIComponent(cursor)}`
      return apiGet<SalesFactListResponse>(
        `/api/companies/${encodeURIComponent(companyId)}/ingestion/ozon/sales-facts${query}`,
      )
    },
    enabled: options?.enabled ?? true,
  })
}
