import type { paths } from '../../../api/schema'

type Query = NonNullable<
  paths['/api/companies/{companyId}/buyout-rate']['get']['parameters']['query']
>

export type BuyoutDays = NonNullable<Query['days']>

export const BUYOUT_WINDOWS = [
  7, 30, 90,
] as const satisfies readonly BuyoutDays[]

const DEFAULT_DAYS: BuyoutDays = 30

export function parseBuyoutDays(raw: string | null): BuyoutDays {
  const value = Number(raw)

  return BUYOUT_WINDOWS.find((window) => window === value) ?? DEFAULT_DAYS
}

export function buyoutSearchWithDays(
  current: URLSearchParams,
  days: BuyoutDays,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('days', String(days))
  next.delete('cursor')

  return next
}

export function buyoutSearchWithCursor(
  current: URLSearchParams,
  cursor: string | null,
): URLSearchParams {
  const next = new URLSearchParams(current)

  if (cursor === null) {
    next.delete('cursor')
  } else {
    next.set('cursor', cursor)
  }

  return next
}
