import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { signupFailure } from './signupFailure'

describe('signupFailure', () => {
  it.each([
    ['email_invalid', 'email'],
    ['password_too_short', 'password'],
    ['company_name_invalid', 'companyName'],
    ['legal_consent_required', 'legalConsent'],
  ] as const)('maps %s to the %s field', (code, field) => {
    expect(
      signupFailure(new ApiError(422, code, 'backend detail')),
    ).toMatchObject({
      kind: 'field',
      field,
    })
  })

  it('requires a new token after an invalid captcha token', () => {
    expect(
      signupFailure(new ApiError(422, 'captcha_invalid', 'backend detail')),
    ).toEqual({
      kind: 'retry-captcha',
      message: 'Не удалось подтвердить проверку. Попробуйте ещё раз',
    })
  })

  it('uses a fixed later message after rate limiting', () => {
    expect(
      signupFailure(new ApiError(429, 'anything', 'backend detail')),
    ).toEqual({
      kind: 'general',
      message: 'Слишком много попыток. Попробуйте позже',
    })
  })

  it('uses a fixed unavailable message when captcha verification is unavailable', () => {
    expect(
      signupFailure(new ApiError(503, 'anything', 'backend detail')),
    ).toEqual({
      kind: 'general',
      message: 'Проверка временно недоступна. Попробуйте позже',
    })
  })

  it('does not expose unknown backend or network details', () => {
    const failure = signupFailure(new Error('SMTP recipient and stack trace'))

    expect(failure).toEqual({
      kind: 'general',
      message: 'Не удалось отправить заявку. Попробуйте позже',
    })
    expect(failure.message).not.toContain('SMTP')
  })
})
