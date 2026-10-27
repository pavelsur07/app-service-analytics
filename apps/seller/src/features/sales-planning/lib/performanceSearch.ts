import type { components } from '../../../api/schema'
import { GROUPINGS, type Grouping } from './salesPlan'
import {
  defaultPerformanceWindow,
  performanceRangeProblem,
} from './performanceWindow'
import { performancePageScope } from './performancePaging'

interface Window {
  from: string
  to: string
  groupBy: Grouping
}
export interface PerformanceSearchState {
  applied: Window
  mode: 'period' | 'cumulative'
  cursor: string | null
}
interface Context {
  companyId: string
  plan: Pick<
    components['schemas']['SalesPlanResponse'],
    'id' | 'version' | 'dateFrom' | 'dateTo'
  >
}

export function readPerformanceSearch(
  search: URLSearchParams,
  context: Context,
  now = new Date(),
): PerformanceSearchState {
  const defaults = {
    ...defaultPerformanceWindow(
      context.plan.dateFrom,
      context.plan.dateTo,
      now,
    ),
    groupBy: 'day' as Grouping,
  }
  const groupBy =
    GROUPINGS.find((option) => option.value === search.get('groupBy'))?.value ??
    'day'
  let applied = {
    from: search.get('from') ?? defaults.from,
    to: search.get('to') ?? defaults.to,
    groupBy,
  }
  if (
    performanceRangeProblem(
      applied.from,
      applied.to,
      applied.groupBy,
      context.plan.dateFrom,
      context.plan.dateTo,
    ) !== null
  )
    applied = defaults
  const scope = performancePageScope(
    context.companyId,
    context.plan.id,
    context.plan.version,
    applied,
  )
  const rawCursor = search.get('cursor')
  const cursor =
    rawCursor !== null &&
    rawCursor !== '' &&
    rawCursor.length <= 2048 &&
    search.get('cursorScope') === scope
      ? rawCursor
      : null
  return {
    applied,
    mode: search.get('mode') === 'cumulative' ? 'cumulative' : 'period',
    cursor,
  }
}

export function writePerformanceSearch(
  search: URLSearchParams,
  state: PerformanceSearchState,
  context: Context,
): URLSearchParams {
  const next = new URLSearchParams(search)
  next.set('view', 'compare')
  next.set('from', state.applied.from)
  next.set('to', state.applied.to)
  next.set('groupBy', state.applied.groupBy)
  next.set('mode', state.mode)
  if (state.cursor === null) {
    next.delete('cursor')
    next.delete('cursorScope')
  } else {
    next.set('cursor', state.cursor)
    next.set(
      'cursorScope',
      performancePageScope(
        context.companyId,
        context.plan.id,
        context.plan.version,
        state.applied,
      ),
    )
  }
  return next
}
