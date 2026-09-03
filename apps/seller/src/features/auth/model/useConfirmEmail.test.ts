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
      removeAuth: () => {
        events.push('remove')
      },
      clearToken: () => undefined,
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
      removeAuth: () => undefined,
      clearToken: () => undefined,
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
      removeAuth: () => undefined,
      clearToken: () => undefined,
    })

    expect(result).toEqual({
      kind: 'expired',
      action: 'resend',
      destination: '/resend-confirmation',
    })
    expect(invalidated).toBe(false)
  })

  it.each([
    [
      'confirmed success',
      async () => ({ outcome: 'confirmed', next: '/onboarding' }),
    ],
    [
      'already-used response',
      async () => {
        throw new ApiError(409, null, 'backend-only detail')
      },
    ],
    [
      'expired response',
      async () => {
        throw new ApiError(410, null, 'backend-only detail')
      },
    ],
    [
      'generic failure',
      async () => {
        throw new Error('unexpected detail')
      },
    ],
  ] as const)('clears the token before returning %s', async (_name, post) => {
    let retainedToken: string | null = 'terminal-token'

    const result = await confirmEmailAttempt('terminal-token', {
      post,
      invalidateAuth: async () => undefined,
      removeAuth: () => undefined,
      clearToken: () => {
        retainedToken = null
      },
    })

    expect(result.kind).not.toBe('transient')
    expect(retainedToken).toBeNull()
  })

  it('retains the token only for a transient POST failure', async () => {
    let retainedToken: string | null = 'retry-token'

    const result = await confirmEmailAttempt('retry-token', {
      post: async () => {
        throw new TypeError('network detail')
      },
      invalidateAuth: async () => undefined,
      removeAuth: () => undefined,
      clearToken: () => {
        retainedToken = null
      },
    })

    expect(result).toEqual({ kind: 'transient', action: 'retry' })
    expect(retainedToken).toBe('retry-token')
  })

  it('removes stale auth and never repeats the POST when invalidation rejects', async () => {
    let postCount = 0
    let retainedToken: string | null = 'confirmed-token'
    let removedQueryKey: readonly unknown[] | null = null
    const dependencies = {
      post: async () => {
        postCount += 1
        return { outcome: 'confirmed', next: '/onboarding' }
      },
      invalidateAuth: async () => {
        throw new TypeError('refetch failed')
      },
      removeAuth: (queryKey: readonly unknown[]) => {
        removedQueryKey = queryKey
      },
      clearToken: () => {
        retainedToken = null
      },
    }

    const result = await confirmEmailAttempt('confirmed-token', dependencies)

    if (result.kind === 'transient' && retainedToken !== null) {
      await confirmEmailAttempt(retainedToken, dependencies)
    }

    expect(result).toEqual({
      kind: 'confirmed',
      action: 'navigate',
      destination: '/onboarding',
    })
    expect(postCount).toBe(1)
    expect(removedQueryKey).toEqual(['auth', 'me'])
    expect(retainedToken).toBeNull()
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
