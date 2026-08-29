import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

type AdminMeResponse = components['schemas']['AdminMeResponse']

export function useLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) =>
      apiPost<AdminMeResponse>('/api/admin/auth/login', credentials),
    onSuccess: () => {
      // Сессия установлена — старое «не авторизован» не должно пережить
      // успешный вход в кэше /api/admin/auth/me.
      void queryClient.invalidateQueries({ queryKey: adminQueryKey('me') })
    },
  })
}
