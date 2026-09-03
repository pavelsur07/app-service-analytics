import { describe, expect, it } from 'vitest'

import { apiPost } from '../../../api/client'
import type { components, paths } from '../../../api/schema'
import { http, server } from '../../../../tests/msw/server'
import { resendRequest } from './useResendConfirmation'

const ENDPOINT = 'http://localhost/api/auth/email-verification/resend'

type ResendRequest = NonNullable<
  NonNullable<
    paths['/api/auth/email-verification/resend']['post']
  >['requestBody']
>['content']['application/json']

describe('resend confirmation API contract', () => {
  it('keeps only email in the generated resend body', () => {
    expect(resendRequest('owner@example.test')).toEqual({
      email: 'owner@example.test',
    })
  })

  it('sends the generated resend body and accepts the neutral response', async () => {
    const request: ResendRequest = { email: 'owner@example.test' }

    server.use(
      http.post(
        '/api/auth/email-verification/resend',
        async ({ request: received, response }) => {
          expect(await received.json()).toEqual(request)
          return response(202).json({ message: 'Instructions accepted.' })
        },
      ),
    )

    await expect(
      apiPost<components['schemas']['SelfRegistrationResponse']>(
        ENDPOINT,
        request,
      ),
    ).resolves.toEqual({ message: 'Instructions accepted.' })
  })
})
