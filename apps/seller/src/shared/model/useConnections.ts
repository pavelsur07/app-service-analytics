import { useQuery } from '@tanstack/react-query'
import { createCompanyApiClient } from '../../api/companyClient'
import type { components } from '../../api/schema'
import { connectionsQueryKey } from '../lib/connectionsQueryKey'

type ConnectionsResponse = components['schemas']['ConnectionsResponse']

// В shared/, а не в features/connections (тот же приём, что у
// useCurrentUser рядом): список подключений компании нужен не только
// собственной фиче, но и app/CompanyLayout (гейт company-scoped
// экранов, resolveCompanyGate) и features/onboarding (симметричное
// решение «форма или экран подключений», resolveOnboardingDecision
// в OnboardingStartPage.tsx), а features/A не импортирует из
// features/B (import/no-restricted-paths, eslint.config.js).
// features/connections/model/useConnections.ts реэкспортирует это имя
// для собственных вызовов внутри той же фичи.
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
