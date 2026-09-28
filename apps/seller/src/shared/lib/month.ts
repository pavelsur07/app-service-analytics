// Месяц отчёта `YYYY-MM` по календарю площадки: общий для экранов,
// которые показывают данные за календарный месяц (полнота данных,
// сверка начислений).

const TIMEZONE = 'Europe/Moscow'

const MONTHS = [
  'январь',
  'февраль',
  'март',
  'апрель',
  'май',
  'июнь',
  'июль',
  'август',
  'сентябрь',
  'октябрь',
  'ноябрь',
  'декабрь',
] as const

/**
 * Текущий месяц по Москве, `YYYY-MM`: дни отчёта — дни площадки,
 * и полночь браузера восточнее Москвы не должна перелистывать месяц раньше.
 */
export function currentMonth(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('sv-SE', {
    timeZone: TIMEZONE,
    year: 'numeric',
    month: '2-digit',
  }).format(now)
}

/** Месяц `YYYY-MM`, сдвинутый на `delta`. Неверная строка — текущий месяц. */
export function shiftMonth(month: string, delta: number): string {
  const match = /^(\d{4})-(\d{2})$/.exec(month)
  if (match === null) {
    return currentMonth()
  }
  const index = Number(match[1]) * 12 + (Number(match[2]) - 1) + delta
  const year = Math.floor(index / 12)
  const monthNumber = (index % 12) + 1

  return `${String(year)}-${String(monthNumber).padStart(2, '0')}`
}

/** «сентябрь 2026». */
export function monthLabel(month: string): string {
  const match = /^(\d{4})-(\d{2})$/.exec(month)
  const name = match === null ? undefined : MONTHS[Number(match[2]) - 1]

  return match === null || name === undefined ? month : `${name} ${match[1]}`
}

/** Самый ранний месяц, который отдаёт API. */
const FIRST_MONTH = '2020-01'

/**
 * Месяц из адреса, если API его примет: `YYYY-MM`, номер 01–12,
 * от `FIRST_MONTH` до текущего. Иначе — `fallback` (по умолчанию текущий): битая или
 * «будущая» ссылка открывает отчёт, а не ошибку, которую не повторить.
 */
export function monthFromParam(
  value: string | null,
  current: string,
  fallback: string = current,
): string {
  const match = /^\d{4}-(\d{2})$/.exec(value ?? '')
  const monthNumber = match === null ? 0 : Number(match[1])
  if (value === null || monthNumber < 1 || monthNumber > 12) {
    return fallback
  }

  return value < FIRST_MONTH || value > current ? fallback : value
}

/** Раньше `FIRST_MONTH` API отчёт не отдаёт. */
export function canGoBack(month: string): boolean {
  return month > FIRST_MONTH
}

/** Следующего месяца отчёт не отдаёт: данных за будущее нет. */
export function canGoForward(month: string, current: string): boolean {
  return month < current
}
