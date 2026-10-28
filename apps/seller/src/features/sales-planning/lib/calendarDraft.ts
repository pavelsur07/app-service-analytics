import type { components, paths } from '../../../api/schema'

export type CalendarSnapshot = Omit<
  components['schemas']['SalesPlanCalendarResponse'],
  'nextCursor'
>
type CalendarWrite =
  paths['/api/companies/{companyId}/sales-plans/{planId}/calendar']['put']['requestBody']['content']['application/json']
export type CalendarEntry = CalendarWrite['entries'][number]
export interface CalendarDraft {
  base: CalendarSnapshot
  entries: CalendarEntry[]
}

export function startCalendarDraft(base: CalendarSnapshot): CalendarDraft {
  return {
    base,
    entries: base.entries.map((entry) => ({
      ...entry,
      marketplaceSku: entry.marketplaceSku ?? null,
    })),
  }
}

export function calendarEntryProblem(
  entry: CalendarEntry,
  season: CalendarSnapshot,
): string | null {
  const valid = (date: string) =>
    /^\d{4}-\d{2}-\d{2}$/.test(date) &&
    !Number.isNaN(Date.parse(`${date}T00:00:00Z`)) &&
    new Date(`${date}T00:00:00Z`).toISOString().slice(0, 10) === date
  if (!valid(entry.dateFrom) || !valid(entry.dateTo))
    return 'Укажите корректные календарные даты.'
  if (entry.dateFrom > entry.dateTo) return 'Конец события раньше начала.'
  if (
    entry.dateFrom < season.seasonStart.date ||
    entry.dateTo > season.seasonEnd.date
  )
    return 'Все даты события должны быть внутри сезона плана.'
  if (entry.kind !== 'sales' && entry.dateFrom !== entry.dateTo)
    return 'Для подсорта и стопа подсорта выберите одну дату.'
  if (entry.marketplaceSku !== null && entry.marketplaceSku.trim() === '')
    return 'Укажите SKU или оставьте поле пустым для всего плана.'
  return null
}

/** A partial/failed read or a newer server version can never rebase local edits. */
export function calendarWriteCommand(
  draft: CalendarDraft,
  current: CalendarSnapshot | undefined,
  ready: boolean,
): CalendarWrite | null {
  if (
    !ready ||
    current === undefined ||
    current.planId !== draft.base.planId ||
    current.version !== draft.base.version ||
    current.status !== 'draft' ||
    draft.base.status !== 'draft' ||
    current.seasonStart.date !== draft.base.seasonStart.date ||
    current.seasonEnd.date !== draft.base.seasonEnd.date ||
    draft.entries.length > 500 ||
    draft.entries.some((entry) => calendarEntryProblem(entry, current) !== null)
  )
    return null
  return { version: draft.base.version, entries: draft.entries }
}
