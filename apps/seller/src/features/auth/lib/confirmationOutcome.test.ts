import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import type { components } from '../../../api/schema'
import { confirmationFailure, confirmationOutcome } from './confirmationOutcome'

type EmailConfirmationResponse =
  components['schemas']['EmailConfirmationResponse']

describe('confirmation outcome', () => {
  it('accepts the exact confirmed outcome and uses the fixed onboarding destination', () => {
    const response: EmailConfirmationResponse = {
      outcome: 'confirmed',
      next: '/onboarding',
    }

    expect(confirmationOutcome(response)).toEqual({
      kind: 'confirmed',
      action: 'navigate',
      destination: '/onboarding',
    })
  })

  it('ignores an untrusted next value on an otherwise confirmed response', () => {
    const response: EmailConfirmationResponse = {
      outcome: 'confirmed',
      next: 'https://attacker.example/collect',
    }

    expect(confirmationOutcome(response)).toEqual({
      kind: 'confirmed',
      action: 'navigate',
      destination: '/onboarding',
    })
  })

  it.each([{}, null, { outcome: 'pending', next: '/onboarding' }])(
    'maps a malformed or unknown success response to a generic safe failure',
    (response) => {
      expect(confirmationOutcome(response)).toEqual({
        kind: 'failure',
        action: 'none',
      })
    },
  )
})

describe('confirmation failure', () => {
  it('maps HTTP 409 to the fixed login action without backend detail', () => {
    expect(
      confirmationFailure(
        new ApiError(409, 'token_already_used', 'backend-only detail'),
      ),
    ).toEqual({
      kind: 'already-used',
      action: 'login',
      destination: '/login',
    })
  })

  it('maps HTTP 410 to the fixed resend action without backend detail', () => {
    expect(
      confirmationFailure(
        new ApiError(410, 'token_expired', 'backend-only detail'),
      ),
    ).toEqual({
      kind: 'expired',
      action: 'resend',
      destination: '/resend-confirmation',
    })
  })

  it.each([new TypeError('network detail'), new ApiError(503, null, 'proxy')])(
    'maps a network or 5xx failure to an in-memory retry',
    (error) => {
      expect(confirmationFailure(error)).toEqual({
        kind: 'transient',
        action: 'retry',
      })
    },
  )

  it.each([
    new ApiError(422, 'validation_failed', 'backend-only detail'),
    new Error('unexpected detail'),
    'not an error object',
  ])('maps every other failure to a generic safe result', (error) => {
    expect(confirmationFailure(error)).toEqual({
      kind: 'failure',
      action: 'none',
    })
  })
})
