import { mutationOptions, type QueryClient } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components, paths } from '../../../api/schema'
import { salesPlanningSectionKey } from './salesPlanningQueryKeys'

type CalendarWrite =
  paths['/api/companies/{companyId}/sales-plans/{planId}/calendar']['put']['requestBody']['content']['application/json']

export function calendarMutationOptions(
  client: QueryClient,
  companyId: string,
  planId: string,
) {
  const invalidate = () =>
    client.invalidateQueries({ queryKey: salesPlanningSectionKey(companyId) })
  return mutationOptions({
    retry: false,
    mutationFn: async (command: CalendarWrite) => {
      if (companyId === '' || planId === '')
        throw new Error('Нужны компания и план.')
      return createCompanyApiClient(companyId).put<
        components['schemas']['SalesPlanVersionResponse']
      >(`/sales-plans/${encodeURIComponent(planId)}/calendar`, command)
    },
    onSuccess: invalidate,
    onError: invalidate,
  })
}
