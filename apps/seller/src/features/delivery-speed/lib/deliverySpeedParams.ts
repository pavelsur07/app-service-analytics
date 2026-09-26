import type { paths } from '../../../api/schema'

type Query = NonNullable<
  paths['/api/companies/{companyId}/delivery-speed']['get']['parameters']['query']
>

export type DeliverySpeedDays = NonNullable<Query['days']>

export const DELIVERY_SPEED_WINDOWS = [
  30, 90,
] as const satisfies readonly DeliverySpeedDays[]

const DEFAULT_DAYS: DeliverySpeedDays = 30

export function parseDeliverySpeedDays(raw: string | null): DeliverySpeedDays {
  const value = Number(raw)

  return (
    DELIVERY_SPEED_WINDOWS.find((window) => window === value) ?? DEFAULT_DAYS
  )
}

export function deliverySpeedSearchWithDays(
  current: URLSearchParams,
  days: DeliverySpeedDays,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('days', String(days))
  next.delete('cursor')

  return next
}

export function deliverySpeedSearchWithCursor(
  current: URLSearchParams,
  cursor: string | null,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('days', String(parseDeliverySpeedDays(current.get('days'))))

  if (cursor === null) {
    next.delete('cursor')
  } else {
    next.set('cursor', cursor)
  }

  return next
}

// Вкладка — в адресе, как и период: ссылка на «что довезти первым»
// открывает именно её, а не первую вкладку.
const DELIVERY_SPEED_VIEWS = ['clusters', 'items', 'buyout', 'routes'] as const

export type DeliverySpeedView = (typeof DELIVERY_SPEED_VIEWS)[number]

const DEFAULT_VIEW: DeliverySpeedView = 'clusters'

export function parseDeliverySpeedView(raw: string | null): DeliverySpeedView {
  return DELIVERY_SPEED_VIEWS.find((view) => view === raw) ?? DEFAULT_VIEW
}

export function deliverySpeedSearchWithView(
  current: URLSearchParams,
  view: DeliverySpeedView,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('view', view)

  return next
}
