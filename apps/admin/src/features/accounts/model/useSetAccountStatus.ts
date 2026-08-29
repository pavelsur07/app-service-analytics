import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

type ClientAccountStatusResponse =
  components['schemas']['ClientAccountStatusResponse']

export function useSetAccountStatus() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { id: string; status: 'active' | 'blocked' }) =>
      apiPost<ClientAccountStatusResponse>(
        `/api/admin/companies/${encodeURIComponent(input.id)}/status`,
        { status: input.status },
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: adminQueryKey('accounts'),
      })
    },
  })
}
