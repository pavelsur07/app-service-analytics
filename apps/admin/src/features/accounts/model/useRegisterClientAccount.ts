import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

type AdminCompanyResponse = components['schemas']['AdminCompanyResponse']

export function useRegisterClientAccount() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: {
      name: string
      ownerEmail: string
      ownerPassword: string
    }) => apiPost<AdminCompanyResponse>('/api/admin/companies', input),
    onSuccess: () => {
      // Список устарел в тот же момент — инвалидируется весь префикс
      // сущности, а не одна страница: новый аккаунт идёт первым, то есть
      // сдвигает все.
      void queryClient.invalidateQueries({
        queryKey: adminQueryKey('accounts'),
      })
    },
  })
}
