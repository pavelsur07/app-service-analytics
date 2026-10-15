import { useMutation } from '@tanstack/react-query'

import { apiPost } from '../../../api/client'
import type { components, paths } from '../../../api/schema'

// Оба вызова — pre-auth allow-list CLAUDE.md §10 (ADR-037): компании
// ещё нет, поэтому apiPost напрямую, не клиент компании.
const REQUEST_ENDPOINT = '/api/auth/password-reset/request' as const
const CONFIRM_ENDPOINT = '/api/auth/password-reset/confirm' as const

type SelfRegistrationResponse =
  components['schemas']['SelfRegistrationResponse']
type PasswordResetResponse = components['schemas']['PasswordResetResponse']

export type RequestPasswordResetRequest = NonNullable<
  NonNullable<paths[typeof REQUEST_ENDPOINT]['post']>['requestBody']
>['content']['application/json']
export type ResetPasswordRequest = NonNullable<
  NonNullable<paths[typeof CONFIRM_ENDPOINT]['post']>['requestBody']
>['content']['application/json']

export interface RequestPasswordResetVariables {
  email: string
  captchaToken: string
}

export function requestPasswordResetBody({
  email,
  captchaToken,
}: RequestPasswordResetVariables): RequestPasswordResetRequest {
  return { email: email.trim(), captchaToken }
}

export function resetPasswordBody(
  token: string,
  password: string,
): ResetPasswordRequest {
  return { token, password }
}

export function useRequestPasswordReset() {
  return useMutation<
    SelfRegistrationResponse,
    Error,
    RequestPasswordResetVariables
  >({
    mutationFn: (variables) =>
      apiPost<SelfRegistrationResponse>(
        REQUEST_ENDPOINT,
        requestPasswordResetBody(variables),
      ),
  })
}

export function useResetPassword() {
  return useMutation<
    PasswordResetResponse,
    Error,
    { token: string; password: string }
  >({
    mutationFn: ({ token, password }) =>
      apiPost<PasswordResetResponse>(
        CONFIRM_ENDPOINT,
        resetPasswordBody(token, password),
      ),
  })
}
