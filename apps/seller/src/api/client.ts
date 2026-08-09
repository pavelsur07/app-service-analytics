import { parseApiError } from './ApiError'

// Единственное место в приложении, где разрешён прямой fetch — везде
// остальное запрещено ESLint-правилом (CLAUDE.md §7). Привязка к компании
// добавится, когда появится Identity/авторизация.
export async function apiGet<T>(path: string): Promise<T> {
  const response = await fetch(path)
  if (!response.ok) {
    throw await parseApiError(response)
  }
  return response.json() as Promise<T>
}
