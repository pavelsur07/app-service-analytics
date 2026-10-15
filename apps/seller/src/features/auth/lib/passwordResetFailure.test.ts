import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import {
  requestResetFailure,
  resetPasswordFailure,
} from './passwordResetFailure'

describe('password reset request failures', () => {
  it('lets 429 win over a contradictory body code', () => {
    expect(
      requestResetFailure(new ApiError(429, 'email_invalid', 'x')),
    ).toEqual({
      kind: 'general',
      message: 'Слишком много попыток. Попробуйте позже',
    })
  })

  it('lets 503 win over a contradictory rate-limit code', () => {
    expect(
      requestResetFailure(
        new ApiError(503, 'password_reset_rate_limited', 'x'),
      ),
    ).toEqual({
      kind: 'general',
      message: 'Проверка временно недоступна. Попробуйте позже',
    })
  })

  it('maps an unavailable captcha to a general retry-later message', () => {
    expect(requestResetFailure(new ApiError(503, null, 'x')).kind).toBe(
      'general',
    )
  })

  it('maps field and captcha codes', () => {
    expect(
      requestResetFailure(new ApiError(422, 'email_invalid', 'x')),
    ).toEqual({
      kind: 'field',
      field: 'email',
      message: 'Введите корректный email.',
    })
    expect(
      requestResetFailure(new ApiError(422, 'captcha_invalid', 'x')).kind,
    ).toBe('retry-captcha')
  })

  it('falls back for network errors and HTML from a proxy', () => {
    expect(requestResetFailure(new TypeError('Failed to fetch')).kind).toBe(
      'general',
    )
    expect(requestResetFailure(new ApiError(502, null, 'HTTP 502')).kind).toBe(
      'general',
    )
  })
})

describe('new password failures', () => {
  it('treats any token rejection as an invalid link', () => {
    expect(
      resetPasswordFailure(new ApiError(422, 'reset_token_invalid', 'x')),
    ).toEqual({ kind: 'invalid-link' })
  })

  it('keeps the form on a server failure even with a token code in the body', () => {
    expect(
      resetPasswordFailure(new ApiError(503, 'reset_token_invalid', 'x')),
    ).toEqual({
      kind: 'general',
      message: 'Не удалось сменить пароль. Попробуйте позже',
    })
    expect(
      resetPasswordFailure(new ApiError(429, 'reset_token_invalid', 'x')).kind,
    ).toBe('general')
  })

  it('maps password validation to the password field', () => {
    expect(
      resetPasswordFailure(new ApiError(422, 'password_too_short', 'x')),
    ).toMatchObject({ kind: 'field', field: 'password' })
    expect(
      resetPasswordFailure(new ApiError(422, 'password_too_long', 'x')),
    ).toMatchObject({ kind: 'field', field: 'password' })
  })

  it('reports rate limiting and unknown failures generally', () => {
    expect(resetPasswordFailure(new ApiError(429, null, 'x')).kind).toBe(
      'general',
    )
    expect(resetPasswordFailure(new ApiError(500, null, 'x')).kind).toBe(
      'general',
    )
  })
})
