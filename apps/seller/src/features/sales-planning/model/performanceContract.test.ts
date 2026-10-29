import { describe, expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'

describe('generated performance contract', () => {
  it('keeps goal pace, sales speed and buyout distinct', () => {
    const point = performanceFixture.skus[0]?.points[0]
    if (point === undefined) throw new Error('Missing fixture point')
    const value = point.period
    expect(value.actualSpeed).toBe('475.000')
    expect(value.observedSalesSpeed).toBe('350.000')
    expect(value.cohort.currentProjectedBuyoutQuantity).toBe(900)
    expect(value.expectedGoalQuantity).toBeNull()
    expect(value.expectedQuantityDeviation).toBeNull()
    expect(value.forecastUnavailableReason).toBe('validation_pending')
    expect(value.cohort.expectedNetSalesQuantity).toBeNull()
    expect(value.cohort.expectedNetRevenue).toBeNull()
    expect(value.cohort.netForecastUnavailableReason).toBe('validation_pending')
    expect(value.cohort.netForecastQuality.assessmentStatus).toBe(
      'not_assessed',
    )
    expect(performanceFixture.netForecast.readAt).toBeNull()
    expect(point.cumulative.cohort).toBeNull()
  })
})
