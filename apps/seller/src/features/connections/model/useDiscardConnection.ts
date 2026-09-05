import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '../../../api/ApiError'
import { createCompanyApiClient } from '../../../api/companyClient'
import { discardConnectionFailure } from '../lib/discardConnectionError'
import { connectionsQueryKey } from './useConnections'

export function useDiscardConnection(companyId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (marketplaceAccountId: string) =>
      createCompanyApiClient(companyId).delete(
        `/connections/${encodeURIComponent(marketplaceAccountId)}`,
      ),
    onSuccess: () => {
      // Строки больше нет — список обязан перечитать себя, иначе
      // удалённая карточка останется на экране до следующей навигации.
      void queryClient.invalidateQueries({
        queryKey: connectionsQueryKey(companyId),
      })
    },
    onError: (error: unknown) => {
      // Часть отказов означает, что список на экране устарел (строку
      // уже удалили из другой вкладки) — разбор той же функцией, что
      // рисует сообщение, иначе текст и поведение разъедутся.
      const code = error instanceof ApiError ? error.code : null
      if (!discardConnectionFailure(code).refetch) {
        return
      }

      void queryClient.invalidateQueries({
        queryKey: connectionsQueryKey(companyId),
      })
    },
  })
}
