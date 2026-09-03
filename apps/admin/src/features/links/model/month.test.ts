import { describe, expect, it } from 'vitest'
import {
  currentUtcMonth,
  formatMonthLabel,
  isCurrentMonth,
  shiftMonth,
} from './month'

describe('link month helpers', () => {
  it('formats the current UTC month', () => {
    expect(currentUtcMonth(new Date('2026-09-03T23:00:00Z'))).toBe('2026-09')
  })

  it('moves across year boundaries in UTC', () => {
    expect(shiftMonth('2026-01', -1)).toBe('2025-12')
    expect(shiftMonth('2026-12', 1)).toBe('2027-01')
  })

  it('recognizes the current UTC month', () => {
    expect(isCurrentMonth('2026-09', new Date('2026-09-03T00:00:00Z'))).toBe(
      true,
    )
    expect(isCurrentMonth('2026-08', new Date('2026-09-03T00:00:00Z'))).toBe(
      false,
    )
  })

  it('builds a stable Russian label without the locale year suffix', () => {
    expect(formatMonthLabel('2026-09')).toBe('сентябрь 2026')
  })
})
