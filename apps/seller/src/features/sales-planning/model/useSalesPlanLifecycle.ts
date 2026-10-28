import {
  mutationOptions,
  useMutation,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { salesPlanningSectionKey } from './salesPlanningQueryKeys'

type Command =
  | {
      kind: 'metric'
      version: number
      metric: components['schemas']['SalesPlanMetric']
    }
  | { kind: 'approve' | 'revision' | 'cancel' | 'discard'; version: number }
type Result =
  | components['schemas']['SalesPlanMetricResponse']
  | components['schemas']['SalesPlanRevisionResponse']
  | components['schemas']['SalesPlanApprovedResponse']
  | components['schemas']['CloseSalesPlanResponse']

export function lifecycleMutationOptions(
  client: QueryClient,
  companyId: string,
  planId: string,
) {
  const invalidate = () =>
    client.invalidateQueries({ queryKey: salesPlanningSectionKey(companyId) })
  return mutationOptions({
    retry: false,
    mutationFn: async (command: Command): Promise<Result> => {
      if (companyId === '' || planId === '')
        throw new Error('Нужны компания и план.')
      const api = createCompanyApiClient(companyId)
      const base = `/sales-plans/${encodeURIComponent(planId)}`
      if (command.kind === 'metric')
        return api.put<components['schemas']['SalesPlanMetricResponse']>(
          `${base}/metric`,
          { version: command.version, metric: command.metric },
        )
      if (command.kind === 'revision')
        return api.post<components['schemas']['SalesPlanRevisionResponse']>(
          `${base}/revisions`,
          { version: command.version },
        )
      if (command.kind === 'approve')
        return api.post<components['schemas']['SalesPlanApprovedResponse']>(
          `${base}/approve`,
          { version: command.version },
        )
      return api.post<components['schemas']['CloseSalesPlanResponse']>(
        `${base}/${command.kind}`,
        { version: command.version },
      )
    },
    onSuccess: invalidate,
    onError: invalidate,
  })
}

export function useSalesPlanLifecycle(companyId: string, planId: string) {
  const client = useQueryClient()
  return useMutation(lifecycleMutationOptions(client, companyId, planId))
}
