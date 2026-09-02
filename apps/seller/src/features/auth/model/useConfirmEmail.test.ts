import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiPost } from '../../../api/client'
import type { components, paths } from '../../../api/schema'
import { http, server } from '../../../../tests/msw/server'
import { confirmationRequest, confirmEmailAttempt } from './useConfirmEmail'

const ENDPOINT = 'http://localhost/api/auth/email-verification/confirm'

type ConfirmationOperation = NonNullable<
  paths['/api/auth/email-verification/confirm']['post']
>
type ConfirmationRequest = NonNullable<
  ConfirmationOperation['requestBody']
>['content']['application/json']
type EmailConfirmationResponse =
  components['schemas']['EmailConfirmationResponse']

describe('confirmation model', () => {
  it('keeps the unchanged in-memory token as the only request field', () => {
    expect(confirmationRequest(' one-use-token ')).toEqual({
      token: ' one-use-token ',
    })
  })

  it('awaits auth-key invalidation after an exact confirmed response', async () => {
    const events: string[] = []
    let invalidatedQueryKey: readonly unknown[] | null = null
    let finishInvalidation: () => void = () => undefined
    const invalidation = new Promise<void>((resolve) => {
      finishInvalidation = resolve
    })
    let settled = false

    const resultPromise = confirmEmailAttempt('one-use-token', {
      post: async (path, request) => {
        events.push(`post:${path}:${request.token}`)
        return { outcome: 'confirmed', next: '/somewhere-else' }
      },
      invalidateAuth: async (queryKey) => {
        events.push('invalidate')
        invalidatedQueryKey = queryKey
        await invalidation
      },
    })

    void resultPromise.then(() => {
      settled = true
    })

    while (events.length < 2) {
      await Promise.resolve()
    }
    await Promise.resolve()

    expect(settled).toBe(false)
    finishInvalidation()

    const result = await resultPromise

    expect(result).toEqual({
      kind: 'confirmed',
      action: 'navigate',
      destination: '/onboarding',
    })
    expect(events).toEqual([
      'post:/api/auth/email-verification/confirm:one-use-token',
      'invalidate',
    ])
    expect(invalidatedQueryKey).toEqual(['auth', 'me'])
  })

  it('does not invalidate auth for a malformed success response', async () => {
    let invalidated = false

    const result = await confirmEmailAttempt('one-use-token', {
      post: async () => ({ outcome: 'unexpected' }),
      invalidateAuth: async () => {
        invalidated = true
      },
    })

    expect(result).toEqual({ kind: 'failure', action: 'none' })
    expect(invalidated).toBe(false)
  })

  it('maps a failed request without invalidating auth', async () => {
    let invalidated = false

    const result = await confirmEmailAttempt('one-use-token', {
      post: async () => {
        throw new ApiError(410, null, 'backend-only detail')
      },
      invalidateAuth: async () => {
        invalidated = true
      },
    })

    expect(result).toEqual({
      kind: 'expired',
      action: 'resend',
      destination: '/resend-confirmation',
    })
    expect(invalidated).toBe(false)
  })
})

describe('confirmation API contract', () => {
  it('sends the exact generated request and accepts a typed 200 response', async () => {
    const request: ConfirmationRequest = { token: 'one-use-token' }

    server.use(
      http.post(
        '/api/auth/email-verification/confirm',
        async ({ request: received, response }) => {
          expect(await received.json()).toEqual(request)
          return response(200).json({
            outcome: 'confirmed',
            next: '/onboarding',
          })
        },
      ),
    )

    await expect(
      apiPost<EmailConfirmationResponse>(ENDPOINT, request),
    ).resolves.toEqual({ outcome: 'confirmed', next: '/onboarding' })
  })

  it('preserves typed HTTP 409 as an ApiError status', async () => {
    const request: ConfirmationRequest = { token: 'consumed-token' }

    server.use(
      http.post(
        '/api/auth/email-verification/confirm',
        async ({ request: received, response }) => {
          expect(await received.json()).toEqual(request)
          return response(409).json({
            outcome: 'already_consumed',
            next: null,
          })
        },
      ),
    )

    const error = await apiPost<EmailConfirmationResponse>(
      ENDPOINT,
      request,
    ).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(409)
  })

  it('preserves typed HTTP 410 as an ApiError status', async () => {
    const request: ConfirmationRequest = { token: 'expired-token' }

    server.use(
      http.post(
        '/api/auth/email-verification/confirm',
        async ({ request: received, response }) => {
          expect(await received.json()).toEqual(request)
          return response(410).json({ outcome: 'expired', next: null })
        },
      ),
    )

    const error = await apiPost<EmailConfirmationResponse>(
      ENDPOINT,
      request,
    ).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(410)
  })
})
