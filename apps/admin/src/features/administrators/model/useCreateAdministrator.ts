import { useMutation } from '@tanstack/react-query'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'

type AdministratorResponse = components['schemas']['AdministratorResponse']

// Роли в теле нет: форма заводит только Admin, и решает это бэкенд
// (ADR-017). Поле, которого нет, нельзя подделать из консоли браузера.
export function useCreateAdministrator() {
  return useMutation({
    mutationFn: (input: { email: string; password: string }) =>
      apiPost<AdministratorResponse>('/api/admin/administrators', input),
  })
}
