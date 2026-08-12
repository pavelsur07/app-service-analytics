import type { components } from './schema'

export type ExtensionMeResponse = components['schemas']['ExtensionMeResponse']
type ValidationErrorResponse = components['schemas']['ValidationErrorResponse']

/**
 * Единственное место в расширении, где разрешён прямой fetch — как
 * apps/seller/src/api/client.ts. Отличие одно и существенное: адрес
 * абсолютный и заголовок Authorization обязателен. Расширение живёт
 * на chrome-extension://, относительный путь ушёл бы в само расширение,
 * а сессионной куки у него нет и быть не должно (ADR-010).
 */
export const API_BASE_URL: string = import.meta.env.DEV
  ? 'http://app.conwix.localhost'
  : 'https://app.conwix.com'

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

/**
 * Токен недействителен: истёк, отозван или участника исключили
 * из компании. Снаружи эти случаи неотличимы намеренно (ADR-010) —
 * расширению во всех трёх нужно одно и то же: показать «переподключитесь».
 */
export function isUnauthorized(error: unknown): boolean {
  return error instanceof ApiError && 401 === error.status
}

export async function fetchMe(token: string): Promise<ExtensionMeResponse> {
  const response = await fetch(`${API_BASE_URL}/api/extension/me`, {
    headers: { Authorization: `Bearer ${token}` },
  })

  if (!response.ok) {
    throw await parseApiError(response)
  }

  return (await response.json()) as ExtensionMeResponse
}

async function parseApiError(response: Response): Promise<ApiError> {
  const body: unknown = await response.json().catch(() => null)

  if (isErrorBody(body)) {
    return new ApiError(response.status, body.code, body.message)
  }

  return new ApiError(response.status, null, `HTTP ${response.status}`)
}

function isErrorBody(value: unknown): value is ValidationErrorResponse {
  if (null === value || 'object' !== typeof value) {
    return false
  }

  const candidate = value as Record<string, unknown>

  return (
    'number' === typeof candidate.status &&
    'string' === typeof candidate.code &&
    'string' === typeof candidate.message
  )
}
