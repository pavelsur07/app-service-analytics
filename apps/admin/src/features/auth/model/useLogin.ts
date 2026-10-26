import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

type AdminMfaStatusResponse = components['schemas']['AdminMfaStatusResponse']

export function useLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) =>
      apiPost<AdminMfaStatusResponse>('/api/admin/auth/login', credentials),
    onSuccess: (status) => {
      queryClient.clear()
      queryClient.setQueryData(adminQueryKey('mfa-status'), status)
    },
  })
}
