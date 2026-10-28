import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceSales } from './PerformanceSales'

it('uses observed sales and source revenue and sales speed, never goal pace or buyout', () => {
  const value = performanceFixture.skus[0]?.points[0]?.period
  if (value === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(<PerformanceSales value={value} />)
  expect(html).toContain('700 шт.')
  expect(html).toContain('350,000 шт./день')
  expect(html).toContain('1 234,56')
  expect(html).not.toContain('475')
  expect(html).not.toContain('900')
  expect(html).toContain('Прогноз продаж —')
  expect(html).toContain('будущие возвраты')
  const unavailable = renderToStaticMarkup(
    <PerformanceSales
      value={{ ...value, cohort: null, observedSalesSpeed: null }}
    />,
  )
  expect(unavailable).not.toContain('>0 шт.')
})
