/**
 * Чистые функции экрана плана продаж (ADR-040). Без React и сети —
 * поэтому покрываются тестом целиком (CLAUDE.md §10).
 */

/** Те же пределы, что на сервере (ADR-040 п. 13, SkuPeriodPlan). */
export const MAX_PERIOD_DAYS = 184
const MAX_TOTAL_QUANTITY = 1_000_000

export type Grouping = 'period' | 'month' | 'week' | 'day'

export const GROUPINGS: readonly { value: Grouping; label: string }[] = [
  { value: 'period', label: 'Весь период' },
  { value: 'month', label: 'Месяц' },
  { value: 'week', label: 'Неделя' },
  { value: 'day', label: 'День' },
]

const STATUS_LABELS: Record<string, string> = {
  draft: 'Черновик',
  approved: 'Утверждён',
  superseded: 'Заменён',
  discarded: 'Отказ',
  cancelled: 'Отменён',
}

/** Статус словом: цвет — только усиление (patterns.md, «Данные и статусы»). */
export function statusLabel(status: string): string {
  return STATUS_LABELS[status] ?? status
}

/**
 * Дни периода включительно, по календарным датам «ГГГГ-ММ-ДД».
 * Часы браузера не участвуют: даты — календарь площадки, и восточнее
 * Москвы локальная полночь сдвинула бы границу.
 */
export function periodDays(dateFrom: string, dateTo: string): number | null {
  const from = Date.parse(`${dateFrom}T00:00:00Z`)
  const to = Date.parse(`${dateTo}T00:00:00Z`)
  if (Number.isNaN(from) || Number.isNaN(to)) {
    return null
  }

  return Math.floor((to - from) / 86_400_000) + 1
}

/**
 * Что не так с периодом черновика — до отправки, чтобы человек не ждал
 * отказа сервера. null — период годится. Сервер проверяет то же самое
 * и остаётся последней линией.
 */
export function periodProblem(dateFrom: string, dateTo: string): string | null {
  const days = periodDays(dateFrom, dateTo)
  if (days === null) {
    return 'Укажите обе даты.'
  }

  if (days < 1) {
    return 'Конец периода раньше начала.'
  }

  if (days > MAX_PERIOD_DAYS) {
    return `Период — ${days} дн., больше ${MAX_PERIOD_DAYS}.`
  }

  return null
}

/**
 * Штуки за период: целое без знака и дробей, не больше потолка.
 * null — «это не количество»; ноль — законный план («не продаём»).
 */
export function parseQuantity(input: string): number | null {
  const normalized = input.trim().replace(/\s/g, '')
  if (!/^\d+$/.test(normalized)) {
    return null
  }

  const value = Number(normalized)

  return value <= MAX_TOTAL_QUANTITY ? value : null
}

/** Подпись корзины: один день — одна дата, иначе диапазон. */
export function bucketLabel(dateFrom: string, dateTo: string): string {
  const short = (date: string) => `${date.slice(8, 10)}.${date.slice(5, 7)}`

  return dateFrom === dateTo
    ? short(dateFrom)
    : `${short(dateFrom)}–${short(dateTo)}`
}
