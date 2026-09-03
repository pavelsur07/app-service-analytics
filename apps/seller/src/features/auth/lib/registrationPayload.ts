import type { paths } from '../../../api/schema'

export const PASSWORD_MIN_LENGTH = 12
export const COMPANY_NAME_MAX_LENGTH = 255

export interface RegistrationFormValues {
  email: string
  password: string
  companyName: string
  legalConsent: boolean
}

export interface RegistrationPayload {
  email: string
  password: string
  companyName: string
  legalConsent: true
}

type SignUpOperation = NonNullable<paths['/api/auth/sign-up']['post']>

export type SignUpRequest = NonNullable<
  SignUpOperation['requestBody']
>['content']['application/json']

export function toRegistrationPayload(
  values: RegistrationFormValues,
): RegistrationPayload | null {
  if (!values.legalConsent) {
    return null
  }

  return {
    email: values.email.trim(),
    password: values.password,
    companyName: values.companyName.trim(),
    legalConsent: true,
  }
}
