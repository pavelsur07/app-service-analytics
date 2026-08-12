import type { components } from './schema'

export type ExtensionMeResponse = components['schemas']['ExtensionMeResponse']
export type CompanySkuListResponse =
  components['schemas']['CompanySkuListResponse']
export type SkuSalesSummaryResponse =
  components['schemas']['SkuSalesSummaryResponse']
type ValidationErrorResponse = components['schemas']['ValidationErrorResponse']

/**
 * Единственное место в расширении, где разрешён прямой fetch — как
 * apps/seller/src/api/client.ts. Отличие одно и существенное: адрес
 * абсолютный и заголовок Authorization обязателен. Расширение живёт
 * на chrome-extension://, относительный путь ушёл бы в само расширение,
 * а сессионной куки у него нет и быть не должно (ADR-010).
 */
// Подставляется сборкой из manifest.config.ts тем же вызовом, что считает
// хосты манифеста (vite.config.ts, define). Не import.meta.env.DEV:
// `vite build --mode development` меняет mode, но не NODE_ENV, поэтому
// DEV в собранном коде остаётся false — манифест разрешал бы localhost,
// а запрос уходил на боевой домен.
declare const __APP_ORIGIN__: string

export const API_BASE_URL: string = __APP_ORIGIN__

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
  return request<ExtensionMeResponse>(token, '/api/extension/me')
}

/**
 * Одна страница артикулов компании. Пагинация — забота вызывающего
 * (shared/catalog.ts): клиенту здесь незачем знать, зачем список
 * выгружают целиком.
 */
export async function fetchSkuPage(
  token: string,
  companyId: string,
  cursor: string | null,
  limit: number,
): Promise<CompanySkuListResponse> {
  const query = new URLSearchParams({ limit: String(limit) })
  if (null !== cursor) {
    query.set('cursor', cursor)
  }

  return request<CompanySkuListResponse>(
    token,
    `/api/extension/companies/${encodeURIComponent(companyId)}/skus?${query.toString()}`,
  )
}

export async function fetchSkuSales(
  token: string,
  companyId: string,
  marketplaceSku: string,
): Promise<SkuSalesSummaryResponse> {
  return request<SkuSalesSummaryResponse>(
    token,
    `/api/extension/companies/${encodeURIComponent(companyId)}/skus/${encodeURIComponent(marketplaceSku)}/sales`,
  )
}

async function request<T>(token: string, path: string): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    headers: { Authorization: `Bearer ${token}` },
  })

  if (!response.ok) {
    throw await parseApiError(response)
  }

  return (await response.json()) as T
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
