import { useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { connectionsQueryKey } from '../../../shared/lib/connectionsQueryKey'

type ConnectionsResponse = components['schemas']['ConnectionsResponse']

/**
 * Реэкспорт: ключ определён в shared (features/onboarding инвалидирует
 * тот же список после подключения, а один feature не импортирует другой
 * напрямую — import/no-restricted-paths, eslint.config.js), но здесь
 * остаётся публичным именем хука для уже существующих вызовов внутри
 * этой же фичи (useReplaceCredentials.ts и тесты).
 */
export { connectionsQueryKey }

export function useConnections(
  companyId: string,
  options?: { enabled?: boolean },
) {
  return useQuery({
    queryKey: connectionsQueryKey(companyId),
    queryFn: () =>
      createCompanyApiClient(companyId).get<ConnectionsResponse>(
        '/connections',
      ),
    enabled: options?.enabled ?? true,
  })
}
