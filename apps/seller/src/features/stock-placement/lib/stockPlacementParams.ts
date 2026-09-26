import type { paths } from '../../../api/schema'

type Query = NonNullable<
  paths['/api/companies/{companyId}/stock-placement']['get']['parameters']['query']
>

export type StockPlacementStatus = NonNullable<Query['status']>

export const STOCK_PLACEMENT_STATUSES = [
  'deficit',
  'normal',
  'surplus',
  'insufficient_data',
  'no_sales',
  'unknown_stock',
] as const satisfies readonly StockPlacementStatus[]

// Границы и умолчания — те же, что проверяет сервер (StockPlacementSql):
// значение из адресной строки вне диапазона не уходит на 422, а
// заменяется умолчанием.
const TARGET_DAYS = { min: 7, max: 90, fallback: 28 } as const
const LEAD_DAYS = { min: 0, max: 60, fallback: 7 } as const

export const TARGET_PRESETS = [14, 28, 42, 56] as const
export const LEAD_PRESETS = [0, 3, 7, 14, 21] as const

export interface StockPlacementView {
  targetDays: number
  leadDays: number
  status: StockPlacementStatus | null
}

function parseDays(
  raw: string | null,
  range: { min: number; max: number; fallback: number },
): number {
  if (raw === null || !/^\d+$/.test(raw)) {
    return range.fallback
  }
  const value = Number(raw)

  return value >= range.min && value <= range.max ? value : range.fallback
}

export function parseStockPlacementView(
  search: URLSearchParams,
): StockPlacementView {
  const status = search.get('status')

  return {
    targetDays: parseDays(search.get('target_days'), TARGET_DAYS),
    leadDays: parseDays(search.get('lead_days'), LEAD_DAYS),
    status:
      STOCK_PLACEMENT_STATUSES.find((candidate) => candidate === status) ??
      null,
  }
}

// Смена параметров расчёта сбрасывает курсор: сервер привязывает его
// к целевому покрытию, сроку поставки и фильтру и чужой отвергает 422.
export function stockPlacementSearchWithView(
  current: URLSearchParams,
  view: StockPlacementView,
): URLSearchParams {
  const next = new URLSearchParams(current)
  next.set('target_days', String(view.targetDays))
  next.set('lead_days', String(view.leadDays))
  if (view.status === null) {
    next.delete('status')
  } else {
    next.set('status', view.status)
  }
  next.delete('cursor')

  return next
}

export function stockPlacementSearchWithCursor(
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

// Предустановки плюс текущее значение, если оно пришло из ссылки
// и в предустановки не входит — иначе select показал бы чужое число.
export function daysOptions(
  presets: readonly number[],
  current: number,
): number[] {
  return presets.includes(current)
    ? [...presets]
    : [...presets, current].sort((left, right) => left - right)
}
