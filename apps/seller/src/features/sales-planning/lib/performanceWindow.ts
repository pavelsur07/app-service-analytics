import type { Grouping } from './salesPlan'
import { periodDays } from './salesPlan'

function shift(day: string, days: number): string {
  const date = new Date(`${day}T00:00:00Z`)
  date.setUTCDate(date.getUTCDate() + days)
  return date.toISOString().slice(0, 10)
}

export function moscowToday(now = new Date()): string {
  const parts = new Intl.DateTimeFormat('en', {
    timeZone: 'Europe/Moscow',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(now)
  const part = (type: string) =>
    parts.find((value) => value.type === type)?.value ?? ''
  return `${part('year')}-${part('month')}-${part('day')}`
}

export function defaultPerformanceWindow(
  seasonFrom: string,
  seasonTo: string,
  now = new Date(),
): { from: string; to: string } {
  const yesterday = shift(moscowToday(now), -1)
  const to =
    yesterday < seasonFrom
      ? seasonFrom
      : yesterday < seasonTo
        ? yesterday
        : seasonTo
  const recent = shift(to, -30)
  return { from: recent > seasonFrom ? recent : seasonFrom, to }
}

export function performanceRangeProblem(
  from: string,
  to: string,
  group: Grouping,
  seasonFrom: string,
  seasonTo: string,
): string | null {
  const valid = (date: string) =>
    /^\d{4}-\d{2}-\d{2}$/.test(date) &&
    !Number.isNaN(Date.parse(`${date}T00:00:00Z`)) &&
    new Date(`${date}T00:00:00Z`).toISOString().slice(0, 10) === date
  if (!valid(from) || !valid(to)) return 'Укажите корректные календарные даты.'
  if (from > to) return 'Конец окна раньше начала.'
  if (from < seasonFrom || to > seasonTo)
    return 'Выберите окно внутри сезона плана.'
  const limit = group === 'day' ? 31 : 184
  const count = periodDays(from, to)
  if (count === null || count > limit)
    return `Для этой группировки выберите не больше ${limit} дней.`
  return null
}
