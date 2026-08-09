import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { authQueryKey } from '../../../shared/lib/authQueryKey'

type LoginResponse = components['schemas']['LoginResponse']

export function useLogin() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) =>
      apiPost<LoginResponse>('/api/auth/login', credentials),
    onSuccess: () => {
      // Сессия установлена — старое "не авторизован" не должно пережить
      // успешный вход в кэше /api/auth/me.
      void queryClient.invalidateQueries({ queryKey: authQueryKey() })
    },
  })
}
