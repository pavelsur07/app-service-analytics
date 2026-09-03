import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { http, server } from '../../../../tests/msw/server'
import { signupFailure } from '../lib/signupFailure'
import type {
  RegistrationPayload,
  SignUpRequest,
} from '../lib/registrationPayload'
import { signUpRequest } from './useSignUp'

const ENDPOINT = 'http://localhost/api/auth/sign-up'

describe('signup API contract', () => {
  it('combines the pending payload with the unchanged one-use captcha token', () => {
    const pending: RegistrationPayload = {
      email: 'owner@example.test',
      password: 'twelve characters',
      companyName: 'Example company',
      legalConsent: true,
    }

    expect(signUpRequest(pending, ' token from widget ')).toEqual({
      ...pending,
      captchaToken: ' token from widget ',
    })
  })

  it('sends the generated camelCase registration body with a captcha token', async () => {
    const request: SignUpRequest = {
      email: 'owner@example.test',
      password: 'twelve characters',
      companyName: 'Example company',
      legalConsent: true,
      captchaToken: 'one-use-widget-token',
    }

    server.use(
      http.post(
        '/api/auth/sign-up',
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

  it('maps a typed validation rejection through ApiError without exposing its detail', async () => {
    server.use(
      http.post('/api/auth/sign-up', ({ response }) =>
        response(422).json({
          status: 422,
          code: 'captcha_invalid',
          message: 'backend-only diagnostic',
        }),
      ),
    )

    const error = await apiPost<
      components['schemas']['SelfRegistrationResponse']
    >(ENDPOINT, {
      email: 'owner@example.test',
      password: 'twelve characters',
      companyName: 'Example company',
      legalConsent: true,
      captchaToken: 'one-use-widget-token',
    }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect(signupFailure(error)).toEqual({
      kind: 'retry-captcha',
      message: 'Не удалось подтвердить проверку. Попробуйте ещё раз',
    })
  })
})
