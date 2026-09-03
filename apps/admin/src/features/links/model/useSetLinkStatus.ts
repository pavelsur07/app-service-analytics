import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ApiError } from '../../../api/ApiError'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { adminQueryKey } from '../../../shared/lib/adminQueryKey'

type ShortLinkResponse = components['schemas']['ShortLinkResponse']

export function useSetLinkStatus(id: string) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (input: { status: 'active' | 'disabled'; version: number }) =>
      apiPost<ShortLinkResponse>(
        `/api/admin/links/${encodeURIComponent(id)}/status`,
        input,
      ),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: adminQueryKey('links'),
      })
    },
    onError: (error) => {
      if (error instanceof ApiError && error.status === 409) {
        void queryClient.invalidateQueries({
          queryKey: adminQueryKey('links'),
        })
      }
    },
  })
}
