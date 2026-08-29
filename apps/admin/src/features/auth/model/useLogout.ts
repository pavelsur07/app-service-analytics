import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'

import { apiPost } from '../../../api/client'

export function useLogout() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: () => apiPost('/api/admin/auth/logout'),
    onSuccess: async () => {
      // Весь кэш, не только auth: администратор видит данные всех
      // клиентов, и оставить их видимыми следующему вошедшему на том же
      // устройстве — ровно то, чего системный контур допускать не должен.
      queryClient.clear()

      // Переход явный, а не через 401 от /api/admin/auth/me: clear()
      // удаляет запрос, но не будит наблюдателя (тот же случай разобран
      // в apps/seller). Здесь мы и так знаем, что сессии больше нет.
      await navigate('/login', { replace: true })
    },
  })
}
