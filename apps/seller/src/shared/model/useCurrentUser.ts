import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../api/client'
import type { components } from '../../api/schema'
import { authQueryKey } from '../lib/authQueryKey'

type MeResponse = components['schemas']['MeResponse']

// В shared/, а не в features/auth (тот же приём, что у useCurrentAdmin
// в apps/admin): «кто я и какие компании доступны» нужен и оболочке
// (app/RequireAuth, app/Sidebar, app/Topbar), и нескольким фичам —
// CompanyListPage (features/auth) и OnboardingStartPage
// (features/onboarding), а features/A не импортирует из features/B
// (import/no-restricted-paths, eslint.config.js).
//
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
