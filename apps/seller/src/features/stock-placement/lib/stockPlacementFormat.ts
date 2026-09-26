import type { StockPlacementStatus } from './stockPlacementParams'

export const NO_DATA = '—'
const QUANTITY = new Intl.NumberFormat('ru-RU')
const RATE = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 })

type Tone = 'neutral' | 'positive' | 'negative' | 'warning'

export const STATUS_PRESENTATION: Record<
  StockPlacementStatus,
  { label: string; tone: Tone }
> = {
  deficit: { label: 'Дефицит', tone: 'negative' },
  normal: { label: 'Норма', tone: 'positive' },
  surplus: { label: 'Излишек', tone: 'warning' },
  insufficient_data: { label: 'Мало продаж', tone: 'neutral' },
  no_sales: { label: 'Нет продаж', tone: 'neutral' },
  unknown_stock: { label: 'Остаток неизвестен', tone: 'warning' },
}

// Штуки: null — остаток неизвестен (нет свежего полного снимка),
// это не ноль и показывается прочерком.
export function formatUnits(value: number | null | undefined): string {
  return value === null || value === undefined
    ? NO_DATA
    : QUANTITY.format(value)
}

// Спрос приходит в тысячных долях штуки в день — целым, без float
// на сервере. Деление здесь только формат: «1,5 шт/дн». Положительный
// спрос меньше видимого знака — «<0,1», чтобы одна продажа за окно
// не выглядела как позиция без продаж.
const MIN_VISIBLE_MILLI = 50

export function formatDemand(milliPerDay: number): string {
  if (milliPerDay > 0 && milliPerDay < MIN_VISIBLE_MILLI) {
    return `<${RATE.format(0.1)} шт/дн`
  }

  return `${RATE.format(milliPerDay / 1000)} шт/дн`
}

export function formatCoverDays(days: number | null | undefined): string {
  return days === null || days === undefined
    ? NO_DATA
    : `${QUANTITY.format(days)} дн`
}

// Справочная скорость Ozon (шт/дн) приходит строкой numeric(12,4). Это
// не деньги, и перевод в число здесь только ради формата с одним знаком.
export function formatOzonRate(value: string | null | undefined): string {
  return value === null || value === undefined
    ? NO_DATA
    : RATE.format(Number(value))
}
