import { useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'

type ConnectionsResponse = components['schemas']['ConnectionsResponse']

/**
 * Ключ отдельной функцией, а не выражением внутри хука: без DOM-окружения
 * в тестах хук не вызвать, и проверить, что companyId в ключе есть,
 * можно только так. Потеря companyId здесь означала бы, что после
 * переключения компании экран покажет чужие подключения из кэша (§7).
 */
export function connectionsQueryKey(companyId: string): readonly unknown[] {
  return companyQueryKey(companyId, 'identity', 'connections')
}

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
