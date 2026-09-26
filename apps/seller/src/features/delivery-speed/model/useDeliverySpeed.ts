import { useQuery } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import type { DeliverySpeedDays } from '../lib/deliverySpeedParams'

export type DeliverySpeedReportResponse =
  components['schemas']['DeliverySpeedReportResponse']

export interface DeliverySpeedParams {
  days: DeliverySpeedDays
  limit: number
  cursor: string | null
}

export function deliverySpeedQueryKey(
  companyId: string,
  params: DeliverySpeedParams,
): readonly unknown[] {
  return companyQueryKey(companyId, 'ingestion', 'delivery-speed', {
    ...params,
  })
}

export function deliverySpeedPath(params: DeliverySpeedParams): string {
  const query = new URLSearchParams({
    days: String(params.days),
    limit: String(params.limit),
  })

  if (params.cursor !== null) {
    query.set('cursor', params.cursor)
  }

  return `/delivery-speed?${query.toString()}`
}

export function useDeliverySpeed(
  companyId: string,
  params: DeliverySpeedParams,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: deliverySpeedQueryKey(companyId, params),
    queryFn: () =>
      createCompanyApiClient(companyId).get<DeliverySpeedReportResponse>(
        deliverySpeedPath(params),
      ),
    enabled: options?.enabled ?? true,
  })
}
