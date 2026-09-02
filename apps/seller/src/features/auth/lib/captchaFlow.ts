import type { RegistrationPayload } from './registrationPayload'

export type CaptchaFlowState =
  | { status: 'idle' }
  | { status: 'checking'; request: RegistrationPayload }
  | { status: 'submitting'; request: RegistrationPayload }
  | {
      status: 'failed'
      message: string
      recovery: CaptchaFailureRecovery
    }

export type CaptchaFailureRecovery = 'remount' | 'reload-page'

export type CaptchaRetryDecision =
  | { action: 'continue'; state: CaptchaFlowState }
  | { action: 'reload-page'; state: CaptchaFlowState }

export function startCaptcha(
  state: CaptchaFlowState,
  request: RegistrationPayload,
): CaptchaFlowState {
  if (state.status !== 'idle') {
    return state
  }

  return { status: 'checking', request }
}

export function showChallenge(state: CaptchaFlowState): CaptchaFlowState {
  return state
}

export function acceptCaptcha(
  state: CaptchaFlowState,
  captchaToken: string,
): CaptchaFlowState {
  if (state.status !== 'checking' || captchaToken.length === 0) {
    return state
  }

  return { status: 'submitting', request: state.request }
}

export function failCaptcha(
  state: CaptchaFlowState,
  message: string,
  recovery: CaptchaFailureRecovery = 'remount',
): CaptchaFlowState {
  if (state.status !== 'idle' && state.status !== 'checking') {
    return state
  }

  return { status: 'failed', message, recovery }
}

export function retryCaptcha(state: CaptchaFlowState): CaptchaRetryDecision {
  if (state.status !== 'failed') {
    return { action: 'continue', state }
  }

  if (state.recovery === 'reload-page') {
    return { action: 'reload-page', state }
  }

  return { action: 'continue', state: resetCaptcha() }
}

export function resetCaptcha(): CaptchaFlowState {
  return { status: 'idle' }
}
