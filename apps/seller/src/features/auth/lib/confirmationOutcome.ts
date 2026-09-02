import { ApiError } from '../../../api/ApiError'

export type ConfirmationResolution =
  | {
      kind: 'confirmed'
      action: 'navigate'
      destination: '/onboarding'
    }
  | {
      kind: 'already-used'
      action: 'login'
      destination: '/login'
    }
  | {
      kind: 'expired'
      action: 'resend'
      destination: '/resend-confirmation'
    }
  | { kind: 'transient'; action: 'retry' }
  | { kind: 'failure'; action: 'none' }

export function confirmationOutcome(response: unknown): ConfirmationResolution {
  if (
    typeof response === 'object' &&
    response !== null &&
    'outcome' in response &&
    response.outcome === 'confirmed'
  ) {
    return {
      kind: 'confirmed',
      action: 'navigate',
      destination: '/onboarding',
    }
  }

  return { kind: 'failure', action: 'none' }
}

export function confirmationFailure(error: unknown): ConfirmationResolution {
  if (error instanceof ApiError) {
    if (error.status === 409) {
      return {
        kind: 'already-used',
        action: 'login',
        destination: '/login',
      }
    }

    if (error.status === 410) {
      return {
        kind: 'expired',
        action: 'resend',
        destination: '/resend-confirmation',
      }
    }

    return error.status >= 500 && error.status < 600
      ? { kind: 'transient', action: 'retry' }
      : { kind: 'failure', action: 'none' }
  }

  return error instanceof TypeError
    ? { kind: 'transient', action: 'retry' }
    : { kind: 'failure', action: 'none' }
}
