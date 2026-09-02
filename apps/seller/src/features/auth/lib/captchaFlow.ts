import type { RegistrationPayload } from './registrationPayload'

export type CaptchaFlowState =
  | { status: 'idle' }
  | { status: 'checking'; request: RegistrationPayload }
  | { status: 'submitting'; request: RegistrationPayload }
  | { status: 'failed'; message: string }

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
): CaptchaFlowState {
  if (state.status !== 'checking') {
    return state
  }

  return { status: 'failed', message }
}

export function resetCaptcha(): CaptchaFlowState {
  return { status: 'idle' }
}
