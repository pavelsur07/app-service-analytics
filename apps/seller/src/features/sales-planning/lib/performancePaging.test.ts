import { expect, it } from 'vitest'
import {
  performanceCursorStack,
  performancePageScope,
} from './performancePaging'

it('drops only cursor pages when a calendar/header edit changes version or scope', () => {
  const window = {
    from: '2026-07-01',
    to: '2026-07-02',
    groupBy: 'period',
  } as const
  const scope = performancePageScope('one', 'plan', 7, window)
  const pages = { scope, stack: [null, 'opaque-bound-to-v7'] }
  expect(performanceCursorStack(pages, scope, 'opaque-bound-to-v7')).toEqual([
    null,
    'opaque-bound-to-v7',
  ])
  expect(
    performanceCursorStack(
      pages,
      performancePageScope('one', 'plan', 8, window),
      null,
    ),
  ).toEqual([null])
  expect(
    performanceCursorStack(
      pages,
      performancePageScope('one', 'plan', 7, { ...window, groupBy: 'week' }),
      null,
    ),
  ).toEqual([null])
})
