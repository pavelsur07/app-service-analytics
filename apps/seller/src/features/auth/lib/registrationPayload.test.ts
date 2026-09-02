import { describe, expect, it } from 'vitest'

import {
  COMPANY_NAME_MAX_LENGTH,
  PASSWORD_MIN_LENGTH,
  toRegistrationPayload,
} from './registrationPayload'

describe('toRegistrationPayload', () => {
  it('trims email and company name without changing the password', () => {
    expect(
      toRegistrationPayload({
        email: '  owner@example.test  ',
        password: '  twelve characters or more  ',
        companyName: '  Example company  ',
        legalConsent: true,
      }),
    ).toEqual({
      email: 'owner@example.test',
      password: '  twelve characters or more  ',
      companyName: 'Example company',
      legalConsent: true,
    })
  })

  it('does not create a payload without explicit legal consent', () => {
    expect(
      toRegistrationPayload({
        email: 'owner@example.test',
        password: 'twelve characters',
        companyName: 'Example company',
        legalConsent: false,
      }),
    ).toBeNull()
  })
})

describe('registration limits', () => {
  it('exposes limits consumed by immediate form validation', () => {
    expect(PASSWORD_MIN_LENGTH).toBe(12)
    expect(COMPANY_NAME_MAX_LENGTH).toBe(255)
  })
})
