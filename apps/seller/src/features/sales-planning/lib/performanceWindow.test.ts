import { expect, it } from 'vitest'
import {
  defaultPerformanceWindow,
  performanceRangeProblem,
} from './performanceWindow'

it('defaults through yesterday in Moscow and explicitly limits visible days', () => {
  expect(
    defaultPerformanceWindow(
      '2026-07-01',
      '2026-07-31',
      new Date('2026-07-03T00:30:00+03:00'),
    ),
  ).toEqual({ from: '2026-07-01', to: '2026-07-02' })
  expect(
    defaultPerformanceWindow(
      '2026-01-01',
      '2026-06-30',
      new Date('2026-06-15T00:00:00+03:00'),
    ),
  ).toEqual({ from: '2026-05-15', to: '2026-06-14' })
  expect(
    performanceRangeProblem(
      '2026-07-01',
      '2026-08-01',
      'day',
      '2026-07-01',
      '2026-12-31',
    ),
  ).toContain('31')
  expect(
    performanceRangeProblem(
      '2026-07-01',
      '2026-08-01',
      'period',
      '2026-07-01',
      '2026-12-31',
    ),
  ).toBeNull()
  expect(
    performanceRangeProblem(
      '2026-02-30',
      '2026-03-01',
      'day',
      '2026-01-01',
      '2026-12-31',
    ),
  ).not.toBeNull()
})
