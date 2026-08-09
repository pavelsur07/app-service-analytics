import { apiGet } from './client'

// Единственный способ обратиться к данным компании (CLAUDE.md §10:
// сетевые запросы только через клиент, привязанный к компании). companyId
// зашивается в клиент один раз при создании — вызывающий код не может
// забыть его подставить или перепутать с другим при копировании кода.
export function createCompanyApiClient(companyId: string) {
  return {
    get: <T>(path: string): Promise<T> =>
      apiGet<T>(`/api/companies/${encodeURIComponent(companyId)}${path}`),
  }
}
