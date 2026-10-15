import { describe, expect, it } from 'vitest'

import { apiPost } from '../../../api/client'
import { ApiError } from '../../../api/ApiError'
import type { components } from '../../../api/schema'
import { http, server } from '../../../../tests/msw/server'
import { resetPasswordFailure } from '../lib/passwordResetFailure'
import { requestPasswordResetBody, resetPasswordBody } from './usePasswordReset'

const TOKEN = '0123456789abcdef'.repeat(4)

describe('password reset API contract', () => {
  it('sends the generated request body and accepts the neutral response', async () => {
    server.use(
      http.post(
        '/api/auth/password-reset/request',
        async ({ request, response }) => {
          expect(await request.json()).toEqual({
            email: 'owner@example.test',
            captchaToken: 'captcha',
          })
          return response(202).json({ message: 'Instructions accepted.' })
        },
      ),
    )

    await expect(
      apiPost<components['schemas']['SelfRegistrationResponse']>(
        'http://localhost/api/auth/password-reset/request',
        requestPasswordResetBody({
          email: '  owner@example.test ',
          captchaToken: 'captcha',
        }),
      ),
    ).resolves.toEqual({ message: 'Instructions accepted.' })
  })

  it('sends token and password and accepts the changed status', async () => {
    server.use(
      http.post(
        '/api/auth/password-reset/confirm',
        async ({ request, response }) => {
          expect(await request.json()).toEqual({
            token: TOKEN,
            password: 'new-password-1234',
          })
          return response(200).json({ status: 'password_changed' })
        },
      ),
    )

    await expect(
      apiPost<components['schemas']['PasswordResetResponse']>(
        'http://localhost/api/auth/password-reset/confirm',
        resetPasswordBody(TOKEN, 'new-password-1234'),
      ),
    ).resolves.toEqual({ status: 'password_changed' })
  })

  it('parses an invalid link answer from the contract', async () => {
    server.use(
      http.post('/api/auth/password-reset/confirm', ({ response }) =>
        response(422).json({
          status: 422,
          code: 'reset_token_invalid',
          message: 'Ссылка недействительна или устарела. Запросите новую.',
        }),
      ),
    )

    const error: unknown = await apiPost(
      'http://localhost/api/auth/password-reset/confirm',
      resetPasswordBody(TOKEN, 'new-password-1234'),
    ).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect(resetPasswordFailure(error)).toEqual({ kind: 'invalid-link' })
  })
})
