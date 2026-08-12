import { apiGet, apiPost } from './client'

// Единственный способ обратиться к данным компании (CLAUDE.md §10:
// сетевые запросы только через клиент, привязанный к компании). companyId
// зашивается в клиент один раз при создании — вызывающий код не может
// забыть его подставить или перепутать с другим при копировании кода.
export function createCompanyApiClient(companyId: string) {
  return {
    get: <T>(path: string): Promise<T> =>
      apiGet<T>(`/api/companies/${encodeURIComponent(companyId)}${path}`),
    // Запись к данным компании идёт тем же путём и по той же причине:
    // companyId зашит в клиент один раз, подставить чужой мимо него
    // невозможно.
    post: <T>(path: string, body?: unknown): Promise<T> =>
      apiPost<T>(
        `/api/companies/${encodeURIComponent(companyId)}${path}`,
        body,
      ),
  }
}
