import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '../../../api/ApiError'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { replaceCredentialsFailure } from '../lib/replaceCredentialsError'
import { connectionsQueryKey } from './useConnections'

type ReplacedCredentialsResponse =
  components['schemas']['ReplacedCredentialsResponse']

export interface ReplaceCredentialsInput {
  marketplaceAccountId: string
  clientId: string
  apiKey: string
  // Версия из списка подключений — обязательна (ADR-008): без неё
  // изменение было бы безусловным и затирало чужую правку.
  version: number
}

export function useReplaceCredentials(companyId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ReplaceCredentialsInput) =>
      createCompanyApiClient(companyId).put<ReplacedCredentialsResponse>(
        `/connections/${encodeURIComponent(input.marketplaceAccountId)}/credentials`,
        {
          clientId: input.clientId,
          apiKey: input.apiKey,
          version: input.version,
        },
      ),
    onSuccess: () => {
      // Версия подключения изменилась, состояние тоже — оставить экран
      // с прежними данными значит показать «нужно переподключить» сразу
      // после успешного переподключения.
      void queryClient.invalidateQueries({
        queryKey: connectionsQueryKey(companyId),
      })
    },
    onError: (error: unknown) => {
      // Часть отказов означает, что данные на экране устарели: версия
      // в форме уже не та, и повторная отправка упрётся в тот же
      // конфликт, пока список не перечитан (ADR-008). Разбор — той же
      // функцией, что рисует сообщение: иначе текст «мы обновили список»
      // и поведение разъезжаются.
      const code = error instanceof ApiError ? error.code : null
      if (!replaceCredentialsFailure(code).refetch) {
        return
      }

      void queryClient.invalidateQueries({
        queryKey: connectionsQueryKey(companyId),
      })
    },
  })
}
