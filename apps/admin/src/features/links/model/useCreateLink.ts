import { useMutation, useQueryClient } from '@tanstack/react-query'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

type ShortLinkResponse = components['schemas']['ShortLinkResponse']

export function useCreateLink() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { name: string; targetUrl: string }) =>
      apiPost<ShortLinkResponse>('/api/admin/links', input),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: adminQueryKey('links'),
      })
    },
  })
}
