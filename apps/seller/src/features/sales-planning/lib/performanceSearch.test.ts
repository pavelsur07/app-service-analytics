import { expect, it } from 'vitest'
import {
  readPerformanceSearch,
  writePerformanceSearch,
} from './performanceSearch'

const context = {
  companyId: 'one',
  plan: {
    id: 'plan',
    version: 7,
    dateFrom: '2026-07-01',
    dateTo: '2026-07-31',
  },
}
const now = new Date('2026-08-01T10:00:00Z')
const view = {
  applied: { from: '2026-07-10', to: '2026-07-20', groupBy: 'week' },
  mode: 'cumulative',
  cursor: 'opaque-page-2',
} as const

it('round-trips applied period, mode and opaque page across reload/back/share', () => {
  const url = writePerformanceSearch(
    new URLSearchParams('calendarCursor=keep'),
    view,
    context,
  )
  expect(url.get('view')).toBe('compare')
  expect(url.get('calendarCursor')).toBe('keep')
  expect(
    readPerformanceSearch(new URLSearchParams(url.toString()), context, now),
  ).toEqual(view)
  const previous = writePerformanceSearch(
    url,
    { ...view, cursor: null, mode: 'period' },
    context,
  )
  expect(readPerformanceSearch(previous, context, now).cursor).toBeNull()
  expect(readPerformanceSearch(url, context, now).mode).toBe('cumulative')
})

it('uses trusted company/plan/header/range scope and strips stale cursor before any request', () => {
  const url = writePerformanceSearch(new URLSearchParams(), view, context)
  for (const next of [
    { ...context, companyId: 'two' },
    { ...context, plan: { ...context.plan, id: 'other' } },
    { ...context, plan: { ...context.plan, version: 8 } },
  ]) {
    const state = readPerformanceSearch(url, next, now)
    expect(state.cursor).toBeNull()
    expect(state.applied).toEqual(view.applied)
    expect(writePerformanceSearch(url, state, next).has('cursor')).toBe(false)
  }
  const changed = new URLSearchParams(url)
  changed.set('groupBy', 'period')
  expect(readPerformanceSearch(changed, context, now).cursor).toBeNull()
  const forgedVersion = new URLSearchParams(url)
  forgedVersion.set('headerVersion', '999')
  expect(readPerformanceSearch(forgedVersion, context, now)).toEqual(view)
})

it('canonicalizes safe defaults and never sends malformed unbounded URL values', () => {
  const parsed = readPerformanceSearch(
    new URLSearchParams('from=bad&to=2027-12-31&groupBy=day&cursor=old'),
    context,
    now,
  )
  expect(parsed.applied).toEqual({
    from: '2026-07-01',
    to: '2026-07-31',
    groupBy: 'day',
  })
  expect(parsed.cursor).toBeNull()
  const url = writePerformanceSearch(new URLSearchParams(), parsed, context)
  expect(url.get('mode')).toBe('period')
})
