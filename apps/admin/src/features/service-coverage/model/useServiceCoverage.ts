import { useQuery } from '@tanstack/react-query'

import { apiGet } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

export type ServiceCoverageResponse =
  components['schemas']['ServiceCoverageResponse']
export type ServiceCoverageRow =
  components['schemas']['ServiceCoverageRowResponse']

// Месяц или предел окна контракта service-coverage v1.
export type CoverageDays = 31 | 93

// Окно в ключе: без него переключение на 93 дня отдало бы 31 из кэша.
export function serviceCoverageQueryKey(days: CoverageDays) {
  return adminQueryKey('service-coverage', { days })
}

export function serviceCoveragePath(days: CoverageDays): string {
  return `/api/admin/services/ozon-fetcher/coverage?days=${String(days)}`
}

// Без автообновления: время сводки на экране, обновляет человек
// кнопкой — устаревшая вкладка не выдаёт себя за текущую.
export function useServiceCoverage(days: CoverageDays) {
  return useQuery({
    queryKey: serviceCoverageQueryKey(days),
    queryFn: () => apiGet<ServiceCoverageResponse>(serviceCoveragePath(days)),
  })
}
