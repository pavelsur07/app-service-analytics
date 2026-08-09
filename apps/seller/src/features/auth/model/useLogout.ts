import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiPost } from '../../../api/client'

export function useLogout() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => apiPost('/api/auth/logout'),
    onSuccess: () => {
      // Весь кэш, не только auth: без этого данные компании предыдущего
      // пользователя могли бы на миг остаться видны следующему, вошедшему
      // на том же устройстве без перезагрузки страницы.
      queryClient.clear()
    },
  })
}
