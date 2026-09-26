import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import type { LocalizationDays } from '../lib/localizationParams'

export type LocalizationReportResponse =
  components['schemas']['LocalizationReportResponse']

export interface LocalizationParams {
  days: LocalizationDays
  limit: number
  cursor: string | null
}

export function localizationQueryKey(
  companyId: string,
  params: LocalizationParams,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'localization', {
    ...params,
  })
}

export function localizationPath(params: LocalizationParams): string {
  const query = new URLSearchParams({
    days: String(params.days),
    limit: String(params.limit),
  })

  if (params.cursor !== null) {
    query.set('cursor', params.cursor)
  }

  return `/localization?${query.toString()}`
}

export function useLocalizationReport(
  companyId: string,
  params: LocalizationParams,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: localizationQueryKey(companyId, params),
    queryFn: () =>
      createCompanyApiClient(companyId).get<LocalizationReportResponse>(
        localizationPath(params),
      ),
    enabled: options?.enabled ?? true,
  })
}
