import { ApiError } from '../../../api/ApiError'

export type SignupFailure =
  | {
      kind: 'field'
      field: 'email' | 'password' | 'companyName' | 'legalConsent'
      message: string
    }
  | { kind: 'retry-captcha'; message: string }
  | { kind: 'general'; message: string }

const FIELD_FAILURES = {
  email_invalid: { field: 'email', message: 'Введите корректный email.' },
  password_too_short: {
    field: 'password',
    message: 'Пароль должен содержать не менее 12 символов.',
  },
  company_name_invalid: {
    field: 'companyName',
    message: 'Введите название компании.',
  },
  legal_consent_required: {
    field: 'legalConsent',
    message: 'Подтвердите согласие с условиями.',
  },
} as const

export function signupFailure(error: unknown): SignupFailure {
  if (error instanceof ApiError) {
    // HTTP 429/503 describe the whole failed attempt, so they take
    // precedence over a contradictory body code. Codes cover API gateways
    // that preserve the business error but replace its status.
    if (error.status === 429) {
      return {
        kind: 'general',
        message: 'Слишком много попыток. Попробуйте позже',
      }
    }

    if (error.status === 503) {
      return {
        kind: 'general',
        message: 'Проверка временно недоступна. Попробуйте позже',
      }
    }

    if (error.code === 'registration_rate_limited') {
      return {
        kind: 'general',
        message: 'Слишком много попыток. Попробуйте позже',
      }
    }

    if (error.code === 'captcha_unavailable') {
      return {
        kind: 'general',
        message: 'Проверка временно недоступна. Попробуйте позже',
      }
    }

    if (error.code !== null && error.code in FIELD_FAILURES) {
      const failure = FIELD_FAILURES[error.code as keyof typeof FIELD_FAILURES]
      return { kind: 'field', ...failure }
    }

    if (error.code === 'captcha_invalid') {
      return {
        kind: 'retry-captcha',
        message: 'Не удалось подтвердить проверку. Попробуйте ещё раз',
      }
    }
  }

  return {
    kind: 'general',
    message: 'Не удалось отправить заявку. Попробуйте позже',
  }
}
