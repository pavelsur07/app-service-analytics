import { describe, expect, it } from 'vitest'

import { countAvailableRateDays } from './buyoutDailySeries'

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
