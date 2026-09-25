import { describe, expect, it } from 'vitest'

import { countAvailableRateDays, preliminaryRanges } from './buyoutDailySeries'

describe('countAvailableRateDays', () => {
  it('counts each line independently when a multi-day series contains nulls', () => {
    expect(
      countAvailableRateDays([
        { actualBuyoutRateBps: 7000, projectedBuyoutRateBps: null },
        { actualBuyoutRateBps: null, projectedBuyoutRateBps: 8000 },
        { actualBuyoutRateBps: null, projectedBuyoutRateBps: null },
      ]),
    ).toEqual({ actualDays: 1, projectedDays: 1 })
  })
})

describe('preliminaryRanges', () => {
  it('returns every contiguous preliminary run, including a single day between mature days', () => {
    expect(
      preliminaryRanges([
        { maturityStatus: 'mature' },
        { maturityStatus: 'preliminary' },
        { maturityStatus: 'mature' },
        { maturityStatus: 'preliminary' },
        { maturityStatus: 'preliminary' },
      ]),
    ).toEqual([
      { start: 1, end: 1 },
      { start: 3, end: 4 },
    ])
  })

  it('returns no ranges when every day is mature', () => {
    expect(
      preliminaryRanges([
        { maturityStatus: 'mature' },
        { maturityStatus: 'mature' },
      ]),
    ).toEqual([])
  })
})
