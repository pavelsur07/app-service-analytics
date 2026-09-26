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
