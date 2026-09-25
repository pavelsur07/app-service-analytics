import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '../../../api/ApiError'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { connectAdvertisingFailure } from '../lib/connectAdvertisingError'
import { connectionsQueryKey } from './useConnections'

type ConnectedAdvertisingResponse =
  components['schemas']['ConnectedAdvertisingResponse']

export interface ConnectAdvertisingInput {
  marketplaceAccountId: string
  clientId: string
  clientSecret: string
  // Версия из списка подключений — обязательна (ADR-008).
  version: number
}

export function useConnectAdvertising(companyId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ConnectAdvertisingInput) =>
      createCompanyApiClient(companyId).put<ConnectedAdvertisingResponse>(
        `/connections/${encodeURIComponent(input.marketplaceAccountId)}/advertising-credentials`,
        {
          clientId: input.clientId,
          clientSecret: input.clientSecret,
          version: input.version,
        },
      ),
    onSuccess: () => {
      // Версия подключения и состояние рекламы изменились.
      void queryClient.invalidateQueries({
        queryKey: connectionsQueryKey(companyId),
      })
    },
    onError: (error: unknown) => {
      const code = error instanceof ApiError ? error.code : null
      if (!connectAdvertisingFailure(code).refetch) {
        return
      }

      void queryClient.invalidateQueries({
        queryKey: connectionsQueryKey(companyId),
      })
    },
  })
}
