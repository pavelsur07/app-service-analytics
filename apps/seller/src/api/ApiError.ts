import type { components } from './schema'

type ValidationErrorResponse = components['schemas']['ValidationErrorResponse']

// Разбор ошибок API (CLAUDE.md §10, обязательное покрытие). Формат
// бэкенда — HTTP-статус + код + сообщение (docs/patterns.md), тип — из
// сгенерированной схемы (ValidationErrorResponse), не описан руками.
// Отсутствие такого тела (сеть упала до ответа, прокси вернул HTML) —
// тоже валидный случай, не повод падать самому.
export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string | null,
    message: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

export async function parseApiError(response: Response): Promise<ApiError> {
  const body: unknown = await response.json().catch(() => null)

  if (isErrorBody(body)) {
    return new ApiError(response.status, body.code, body.message)
  }

  return new ApiError(response.status, null, `HTTP ${response.status}`)
}

function isErrorBody(value: unknown): value is ValidationErrorResponse {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as Record<string, unknown>).status === 'number' &&
    typeof (value as Record<string, unknown>).code === 'string' &&
    typeof (value as Record<string, unknown>).message === 'string'
  )
}
