import { ApiError } from '../../../api/ApiError'

// Разбор ошибок двух шагов сброса пароля (ADR-037). Статусы 429 и 5xx
// описывают попытку целиком и проверяются раньше кода в теле — как
// у регистрации. На шаге нового пароля это ещё и сохранность токена:
// временный сбой не должен превратиться в «ссылка недействительна»,
// иначе форма исчезнет, а токен из адреса уже стёрт.
export type RequestResetFailure =
  | { kind: 'field'; field: 'email'; message: string }
  | { kind: 'retry-captcha'; message: string }
  | { kind: 'general'; message: string }

export type ResetPasswordFailure =
  | { kind: 'invalid-link' }
  | { kind: 'field'; field: 'password'; message: string }
  | { kind: 'general'; message: string }

const TOO_MANY = 'Слишком много попыток. Попробуйте позже'
const UNAVAILABLE = 'Проверка временно недоступна. Попробуйте позже'
const REQUEST_FAILED = 'Не удалось отправить письмо. Попробуйте позже'
const RESET_FAILED = 'Не удалось сменить пароль. Попробуйте позже'

export function requestResetFailure(error: unknown): RequestResetFailure {
  if (error instanceof ApiError) {
    if (error.status === 429) {
      return { kind: 'general', message: TOO_MANY }
    }

    if (error.status === 503) {
      return { kind: 'general', message: UNAVAILABLE }
    }

    if (error.status >= 500) {
      return { kind: 'general', message: REQUEST_FAILED }
    }

    if (error.code === 'password_reset_rate_limited') {
      return { kind: 'general', message: TOO_MANY }
    }

    if (error.code === 'captcha_unavailable') {
      return { kind: 'general', message: UNAVAILABLE }
    }

    if (error.code === 'email_invalid') {
      return {
        kind: 'field',
        field: 'email',
        message: 'Введите корректный email.',
      }
    }

    if (error.code === 'captcha_invalid') {
      return {
        kind: 'retry-captcha',
        message: 'Не удалось подтвердить проверку. Попробуйте ещё раз',
      }
    }
  }

  return { kind: 'general', message: REQUEST_FAILED }
}

export function resetPasswordFailure(error: unknown): ResetPasswordFailure {
  if (error instanceof ApiError) {
    if (error.status === 429) {
      return { kind: 'general', message: TOO_MANY }
    }

    if (error.status >= 500) {
      return { kind: 'general', message: RESET_FAILED }
    }

    if (error.code === 'password_reset_rate_limited') {
      return { kind: 'general', message: TOO_MANY }
    }

    if (error.code === 'reset_token_invalid') {
      return { kind: 'invalid-link' }
    }

    if (error.code === 'password_too_short') {
      return {
        kind: 'field',
        field: 'password',
        message: 'Пароль должен содержать не менее 12 символов.',
      }
    }

    if (error.code === 'password_too_long') {
      return {
        kind: 'field',
        field: 'password',
        message: 'Пароль слишком длинный.',
      }
    }
  }

  return { kind: 'general', message: RESET_FAILED }
}
