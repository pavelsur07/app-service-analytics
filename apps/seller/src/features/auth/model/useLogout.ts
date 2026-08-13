import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'

import { apiPost } from '../../../api/client'

export function useLogout() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()

  return useMutation({
    mutationFn: () => apiPost('/api/auth/logout'),
    onSuccess: async () => {
      // Весь кэш, не только auth: без этого данные компании предыдущего
      // пользователя могли бы на миг остаться видны следующему, вошедшему
      // на том же устройстве без перезагрузки страницы.
      queryClient.clear()

      // Переход явный, а не через 401 от /api/auth/me. Расчёт на то, что
      // очищенный кэш сам перезапросится, получит 401 и RequireAuth уведёт
      // на вход, не оправдался: clear() удаляет запрос, но не будит
      // наблюдателя, и человек оставался на экране компании после выхода.
      // Здесь мы и так знаем, что сессии больше нет, — незачем выяснять
      // это у сервера повторно.
      await navigate('/login', { replace: true })
    },
  })
}
