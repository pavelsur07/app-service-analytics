import { useCallback, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router'

import { apiPost } from '../../../api/client'
import type { components, paths } from '../../../api/schema'
import { authQueryKey } from '../../../shared/lib/authQueryKey'
import {
  confirmationFailure,
  confirmationOutcome,
} from '../lib/confirmationOutcome'
import type { ConfirmationResolution } from '../lib/confirmationOutcome'

const CONFIRMATION_ENDPOINT = '/api/auth/email-verification/confirm' as const

type ConfirmationOperation = NonNullable<
  paths['/api/auth/email-verification/confirm']['post']
>
export type ConfirmationRequest = NonNullable<
  ConfirmationOperation['requestBody']
>['content']['application/json']
type EmailConfirmationResponse =
  components['schemas']['EmailConfirmationResponse']

interface ConfirmationAttemptDependencies {
  post(
    path: typeof CONFIRMATION_ENDPOINT,
    request: ConfirmationRequest,
  ): Promise<EmailConfirmationResponse>
  invalidateAuth(queryKey: ReturnType<typeof authQueryKey>): Promise<unknown>
}

export type ConfirmEmailState =
  | { kind: 'idle' }
  | { kind: 'pending' }
  | { kind: 'missing' }
  | ConfirmationResolution

export function confirmationRequest(token: string): ConfirmationRequest {
  return { token }
}

export async function confirmEmailAttempt(
  token: string,
  dependencies: ConfirmationAttemptDependencies,
): Promise<ConfirmationResolution> {
  try {
    const response = await dependencies.post(
      CONFIRMATION_ENDPOINT,
      confirmationRequest(token),
    )
    const resolution = confirmationOutcome(response)

    if (resolution.kind === 'confirmed') {
      await dependencies.invalidateAuth(authQueryKey())
    }

    return resolution
  } catch (error: unknown) {
    return confirmationFailure(error)
  }
}

export function useConfirmEmail() {
  const queryClient = useQueryClient()
  const navigate = useNavigate()
  const tokenRef = useRef<string | null>(null)
  const startedRef = useRef(false)
  const inFlightRef = useRef(false)
  const [state, setState] = useState<ConfirmEmailState>({ kind: 'idle' })

  const attempt = useCallback(
    async (token: string) => {
      if (inFlightRef.current) {
        return
      }

      inFlightRef.current = true
      setState({ kind: 'pending' })

      const resolution = await confirmEmailAttempt(token, {
        post: (path, request) =>
          apiPost<EmailConfirmationResponse>(path, request),
        invalidateAuth: (queryKey) =>
          queryClient.invalidateQueries({ queryKey }),
      })

      inFlightRef.current = false
      setState(resolution)

      if (resolution.kind === 'confirmed') {
        await navigate('/onboarding', { replace: true })
      }
    },
    [navigate, queryClient],
  )

  const start = useCallback(
    (token: string | null) => {
      if (startedRef.current) {
        return
      }

      startedRef.current = true

      if (token === null || token.trim().length === 0) {
        setState({ kind: 'missing' })
        return
      }

      tokenRef.current = token
      void attempt(token)
    },
    [attempt],
  )

  const retry = useCallback(() => {
    if (state.kind !== 'transient' || tokenRef.current === null) {
      return
    }

    void attempt(tokenRef.current)
  }, [attempt, state.kind])

  return { state, start, retry }
}
