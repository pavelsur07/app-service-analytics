import { useQuery } from '@tanstack/react-query'
import { apiGet } from '../../api/client'
import type { components } from '../../api/schema'
import { adminQueryKey } from '../lib/adminQueryKey'

type AdminMeResponse = components['schemas']['AdminMeResponse']

// В shared/, а не в features/auth: «кто я» нужен и оболочке (app/),
// и обеим фичам, а features/A не импортирует из features/B.
//
// Одна точка правды «кто я и какая у меня роль» — и для проверки живой
// сессии (RequireAuth), и для решения, показывать ли раздел управления
// администраторами. Роль отсюда — подсказка интерфейсу: настоящую
// проверку делает бэкенд (#[IsGranted]), спрятанная кнопка защитой
// не является.
export function useCurrentAdmin() {
  return useQuery({
    queryKey: adminQueryKey('me'),
    queryFn: () => apiGet<AdminMeResponse>('/api/admin/auth/me'),
  })
}
