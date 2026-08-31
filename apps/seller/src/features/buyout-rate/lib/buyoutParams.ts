import type { paths } from '../../../api/schema'

type Query = NonNullable<
  paths['/api/companies/{companyId}/buyout-rate']['get']['parameters']['query']
>

export type BuyoutDays = NonNullable<Query['days']>
export type BuyoutSort = NonNullable<Query['sort']>
export type BuyoutSortDirection = NonNullable<Query['direction']>

export const BUYOUT_WINDOWS = [
  7, 30, 90,
] as const satisfies readonly BuyoutDays[]

const BUYOUT_SORTS = [
  'ordered',
  'actual_buyout',
] as const satisfies readonly BuyoutSort[]

const DEFAULT_DAYS: BuyoutDays = 30
const DEFAULT_SORT: BuyoutSort = 'ordered'
const DEFAULT_DIRECTION: BuyoutSortDirection = 'desc'

export function parseBuyoutDays(raw: string | null): BuyoutDays {
  const value = Number(raw)

  return BUYOUT_WINDOWS.find((window) => window === value) ?? DEFAULT_DAYS
}

export function parseBuyoutSort(raw: string | null): BuyoutSort {
  return BUYOUT_SORTS.find((sort) => sort === raw) ?? DEFAULT_SORT
}

export function parseBuyoutSortDirection(
  raw: string | null,
): BuyoutSortDirection {
  return raw === 'asc' || raw === 'desc' ? raw : DEFAULT_DIRECTION
}

export function nextBuyoutSort(
  clicked: BuyoutSort,
  current: BuyoutSort,
  direction: BuyoutSortDirection,
): { sort: BuyoutSort; direction: BuyoutSortDirection } {
  if (clicked !== current) {
    return { sort: clicked, direction: 'desc' }
  }

  return { sort: clicked, direction: direction === 'asc' ? 'desc' : 'asc' }
}

export function buyoutSearchWithDays(
  current: URLSearchParams,
  days: BuyoutDays,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('days', String(days))
  next.delete('cursor')
  next.set('sort', parseBuyoutSort(current.get('sort')))
  next.set('direction', parseBuyoutSortDirection(current.get('direction')))

  return next
}

export function buyoutSearchWithCursor(
  current: URLSearchParams,
  cursor: string | null,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('sort', parseBuyoutSort(current.get('sort')))
  next.set('direction', parseBuyoutSortDirection(current.get('direction')))

  if (cursor === null) {
    next.delete('cursor')
  } else {
    next.set('cursor', cursor)
  }

  return next
}

export function buyoutSearchWithSort(
  current: URLSearchParams,
  sort: BuyoutSort,
  direction: BuyoutSortDirection,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('sort', sort)
  next.set('direction', direction)
  next.delete('cursor')

  return next
}
