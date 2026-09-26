import type { paths } from '../../../api/schema'

type Query = NonNullable<
  paths['/api/companies/{companyId}/localization']['get']['parameters']['query']
>

export type LocalizationDays = NonNullable<Query['days']>

export const LOCALIZATION_WINDOWS = [
  30, 90,
] as const satisfies readonly LocalizationDays[]

const DEFAULT_DAYS: LocalizationDays = 30

export function parseLocalizationDays(raw: string | null): LocalizationDays {
  const value = Number(raw)

  return LOCALIZATION_WINDOWS.find((window) => window === value) ?? DEFAULT_DAYS
}

export function localizationSearchWithDays(
  current: URLSearchParams,
  days: LocalizationDays,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('days', String(days))
  next.delete('cursor')

  return next
}

export function localizationSearchWithCursor(
  current: URLSearchParams,
  cursor: string | null,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('days', String(parseLocalizationDays(current.get('days'))))

  if (cursor === null) {
    next.delete('cursor')
  } else {
    next.set('cursor', cursor)
  }

  return next
}

// Вкладка — в адресе, как и период: ссылка на «что куда довезти»
// открывает именно её, а не первую вкладку.
const LOCALIZATION_VIEWS = ['clusters', 'items'] as const

export type LocalizationView = (typeof LOCALIZATION_VIEWS)[number]

const DEFAULT_VIEW: LocalizationView = 'clusters'

export function parseLocalizationView(raw: string | null): LocalizationView {
  return LOCALIZATION_VIEWS.find((view) => view === raw) ?? DEFAULT_VIEW
}

export function localizationSearchWithView(
  current: URLSearchParams,
  view: LocalizationView,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('view', view)

  return next
}
