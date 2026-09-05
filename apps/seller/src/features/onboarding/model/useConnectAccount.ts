import { useMutation, useQueryClient } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { connectionsQueryKey } from '../../../shared/lib/connectionsQueryKey'

type ConnectedAccountResponse =
  components['schemas']['ConnectedAccountResponse']

export interface ConnectAccountInput {
  name: string
  clientId: string
  apiKey: string
}

export function useConnectAccount(companyId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ConnectAccountInput) =>
      createCompanyApiClient(companyId).post<ConnectedAccountResponse>(
        '/connections',
        input,
      ),
    onSuccess: () => {
      // Список подключений изменился, и от него зависит гейт онбординга:
      // оставить кэш прежним значит увести клиента обратно на форму
      // сразу после успешного подключения.
      void queryClient.invalidateQueries({
        queryKey: connectionsQueryKey(companyId),
      })
    },
  })
}
