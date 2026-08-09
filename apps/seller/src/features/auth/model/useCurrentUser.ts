import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../../api/client'
import type { components } from '../../../api/schema'
import { authQueryKey } from '../../../shared/lib/authQueryKey'

type MeResponse = components['schemas']['MeResponse']

// Одна точка правды "кто я и какие компании доступны" — используется
// и при загрузке приложения (RequireAuth проверяет живую сессию), и на
// экране выбора компании. retry: false — глобальная настройка QueryClient
// (app/Root.tsx), здесь не дублируется.
export function useCurrentUser() {
  return useQuery({
    queryKey: authQueryKey(),
    queryFn: () => apiGet<MeResponse>('/api/auth/me'),
  })
}
