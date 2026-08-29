import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

type AdminCompanyListResponse =
  components['schemas']['AdminCompanyListResponse']

// Страница в ключе: без неё переход на вторую страницу отдал бы первую
// из кэша (CLAUDE.md §7 — та же причина, что у companyId у продавца,
// хотя компании здесь и нет).
export function useClientAccounts(page: number) {
  return useQuery({
    queryKey: adminQueryKey('accounts', { page }),
    queryFn: () =>
      apiGet<AdminCompanyListResponse>(
        `/api/admin/companies?page=${String(page)}`,
      ),
  })
}
