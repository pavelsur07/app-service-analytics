import type { RegistrationPayload } from './registrationPayload'

// Параметр типа — запрос формы, ждущий токена капчи: регистрация
// (ADR-021) и запрос сброса пароля (ADR-037) идут через один автомат.
export type CaptchaFlowState<TRequest = RegistrationPayload> =
  | { status: 'idle' }
  | { status: 'checking'; request: TRequest }
  | { status: 'submitting'; request: TRequest }
  | {
      status: 'failed'
      message: string
      recovery: CaptchaFailureRecovery
    }

type CaptchaFailureRecovery = 'remount' | 'reload-page'

export type CaptchaRetryDecision<TRequest = RegistrationPayload> =
  | { action: 'continue'; state: CaptchaFlowState<TRequest> }
  | { action: 'reload-page'; state: CaptchaFlowState<TRequest> }

export function startCaptcha<TRequest = RegistrationPayload>(
  state: CaptchaFlowState<TRequest>,
  request: TRequest,
): CaptchaFlowState<TRequest> {
  if (state.status !== 'idle') {
    return state
  }

  return { status: 'checking', request }
}

export function showChallenge<TRequest = RegistrationPayload>(
  state: CaptchaFlowState<TRequest>,
): CaptchaFlowState<TRequest> {
  return state
}

export function acceptCaptcha<TRequest = RegistrationPayload>(
  state: CaptchaFlowState<TRequest>,
  captchaToken: string,
): CaptchaFlowState<TRequest> {
  if (state.status !== 'checking' || captchaToken.length === 0) {
    return state
  }

  return { status: 'submitting', request: state.request }
}

export function failCaptcha<TRequest = RegistrationPayload>(
  state: CaptchaFlowState<TRequest>,
  message: string,
): CaptchaFlowState<TRequest> {
  if (state.status !== 'checking') {
    return state
  }

  return { status: 'failed', message, recovery: 'remount' }
}

export function failCaptchaLoader<TRequest = RegistrationPayload>(
  state: CaptchaFlowState<TRequest>,
  message: string,
): CaptchaFlowState<TRequest> {
  if (state.status !== 'idle' && state.status !== 'checking') {
    return state
  }

  return { status: 'failed', message, recovery: 'reload-page' }
}

export function retryCaptcha<TRequest = RegistrationPayload>(
  state: CaptchaFlowState<TRequest>,
): CaptchaRetryDecision<TRequest> {
  if (state.status !== 'failed') {
    return { action: 'continue', state }
  }

  if (state.recovery === 'reload-page') {
    return { action: 'reload-page', state }
  }

  return { action: 'continue', state: resetCaptcha<TRequest>() }
}

export function resetCaptcha<
  TRequest = RegistrationPayload,
>(): CaptchaFlowState<TRequest> {
  return { status: 'idle' }
}
