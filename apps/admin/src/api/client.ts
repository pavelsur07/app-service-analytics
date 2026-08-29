import { parseApiError } from './ApiError'

// Единственное место в приложении, где разрешён прямой fetch — везде
// остальное запрещено ESLint-правилом (CLAUDE.md §7).
//
// Привязки к компании здесь нет и не будет: системный контур управляет
// сервисом целиком, а не отдельной компанией (ADR-017). Аналог
// createCompanyApiClient из apps/seller тут был бы бессмыслен.
export async function apiGet<T>(path: string): Promise<T> {
  const response = await fetch(path)
  if (!response.ok) {
    throw await parseApiError(response)
  }
  return response.json() as Promise<T>
}

// POST — вход, выход, заведение администратора. Same-origin (nginx
// проксирует /api на тот же хост, что и фронтенд), поэтому кука сессии
// уходит с fetch без настройки credentials. T по умолчанию unknown —
// вызовы, которым тело ответа не нужно (logout), не обязаны типом
// из схемы.
export async function apiPost<T = unknown>(
  path: string,
  body?: unknown,
): Promise<T> {
  const response = await fetch(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  })
  if (!response.ok) {
    throw await parseApiError(response)
  }
  return response.json() as Promise<T>
}
