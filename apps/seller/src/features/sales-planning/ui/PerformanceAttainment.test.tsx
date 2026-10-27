import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceAttainment } from './PerformanceAttainment'

it('uses server attainment and signed deviation, keeping zero targets undefined', () => {
  const value = performanceFixture.skus[0]?.points[0]?.period
  if (value === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(<PerformanceAttainment value={value} />)
  expect(html).toContain('95%')
  expect(html).toContain('-50 шт.')
  expect(html).not.toContain('90%')
  const zero = renderToStaticMarkup(
    <PerformanceAttainment
      value={{
        ...value,
        planQuantity: 0,
        attainmentBps: null,
        quantityDeviation: 950,
      }}
    />,
  )
  expect(zero).toContain('950 шт.')
  expect(zero).toContain('Нулевая цель: процент не определён')
  const partial = renderToStaticMarkup(
    <PerformanceAttainment
      value={{
        ...value,
        attainmentBps: null,
        quantityDeviation: null,
        revenueDeviation: null,
        actualGoalQuantity: null,
        actualSpeed: null,
      }}
    />,
  )
  expect(partial).not.toContain('0%')
})
