import type { Period, Preview } from '../model/types'

export type Selection =
  | { periods: Period[] }
  | { shortcut: { kind: 'last_closed_months'; months: 3 | 6 | 12 } }
  | { shortcut: { kind: 'previous_year'; period: Period } }

export function displayedPeriods(
  selection: Selection,
  preview: Preview | undefined,
): Period[] {
  return 'periods' in selection ? selection.periods : (preview?.periods ?? [])
}
export function reachesCurrent(periods: Period[], today: string): boolean {
  const month = today.slice(0, 7)
  return periods.some((p) => p.fromMonth <= month && p.toMonth >= month)
}
export function validPeriods(periods: Period[], today: string): boolean {
  const month = today.slice(0, 7)
  return (
    periods.length > 0 &&
    periods.length <= 24 &&
    periods.every(
      (p) =>
        /^20\d{2}-(0[1-9]|1[0-2])$/.test(p.fromMonth) &&
        /^20\d{2}-(0[1-9]|1[0-2])$/.test(p.toMonth) &&
        p.fromMonth <= p.toMonth &&
        p.toMonth <= month &&
        p.name.length <= 100,
    )
  )
}
export function previewInput(
  selection: Selection,
  includeCurrentMonth: boolean,
  categoryPath: string | null,
  existing: Period[] = [],
): Record<string, unknown> {
  const selected =
    'periods' in selection && existing.length > 0
      ? { periods: [...existing, ...selection.periods] }
      : selection
  return {
    ...selected,
    includeCurrentMonth,
    ...(categoryPath === null ? {} : { categoryPath }),
  }
}
export function previousYear(period: Period): Period {
  return {
    fromMonth: `${Number(period.fromMonth.slice(0, 4)) - 1}${period.fromMonth.slice(4)}`,
    toMonth: `${Number(period.toMonth.slice(0, 4)) - 1}${period.toMonth.slice(4)}`,
    name: period.name,
  }
}
