import { useMutation, useQueryClient } from '@tanstack/react-query'

import { createCompanyApiClient } from '../../../api/companyClient'
import type { components, paths } from '../../../api/schema'
import { connectionsQueryKey } from '../../../shared/lib/connectionsQueryKey'

type ConnectedAccountResponse =
  components['schemas']['ConnectedAccountResponse']

// Тип тела запроса — из сгенерированной схемы (CLAUDE.md §10), тот же
// приём, что у ConfirmationRequest в useConfirmEmail.ts: путь берётся
// целиком, а не собирается из company-scoped относительного адреса
// (createCompanyApiClient подставляет companyId сам, схема описывает
// путь с {companyId} в исходном виде).
type ConnectOperation = paths['/api/companies/{companyId}/connections']['post']
export type ConnectAccountInput = NonNullable<
  ConnectOperation['requestBody']
>['content']['application/json']

const CONNECT_ENDPOINT = '/connections' as const

interface ConnectAccountDependencies {
  post(
    path: typeof CONNECT_ENDPOINT,
    body: ConnectAccountInput,
  ): Promise<ConnectedAccountResponse>
  invalidateConnections(queryKey: readonly unknown[]): Promise<unknown>
}

/**
 * Отдельная функция с инъекцией зависимостей — тот же приём, что
 * у `confirmEmailAttempt` в useConfirmEmail.ts. Хук с реальным `fetch`
 * и живым `QueryClient` собрать в тесте без DOM-окружения нельзя
 * (vite.config.ts, test.environment: 'node'), а проверить, что запрос
 * уходит по /connections с введёнными полями и что по успеху
 * инвалидируется ключ ровно той компании, с которой создан хук —
 * обязательное покрытие §10 — можно только так: подставными
 * зависимостями, без `useMutation` и без сети.
 */
export async function connectAccount(
  companyId: string,
  input: ConnectAccountInput,
  dependencies: ConnectAccountDependencies,
): Promise<ConnectedAccountResponse> {
  const response = await dependencies.post(CONNECT_ENDPOINT, input)

  // Список подключений изменился, и от него зависит гейт онбординга:
  // оставить кэш прежним значит увести клиента обратно на форму сразу
  // после успешного подключения. До инвалидации доходит только здесь,
  // после успешного `post` — отказ площадки не должен трогать кэш чужого,
  // уже прочитанного списка.
  await dependencies.invalidateConnections(connectionsQueryKey(companyId))

  return response
}

export function useConnectAccount(companyId: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: ConnectAccountInput) =>
      connectAccount(companyId, input, {
        post: (path, body) =>
          createCompanyApiClient(companyId).post<ConnectedAccountResponse>(
            path,
            body,
          ),
        invalidateConnections: (queryKey) =>
          queryClient.invalidateQueries({ queryKey }),
      }),
  })
}
