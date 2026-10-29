import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceSales as MetricRows } from './PerformanceSales'
import { performanceColumns } from '../../../../tests/fixtures/performanceColumns'
import type { components } from '../../../api/schema'

function PerformanceSales({
  value,
}: {
  value: components['schemas']['PerformanceValuesResponse']
}) {
  return <MetricRows columns={performanceColumns(value)} currency="RUB" />
}

it('uses observed sales and source revenue and sales speed, never goal pace or buyout', () => {
  const value = performanceFixture.skus[0]?.points[0]?.period
  if (value === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(<PerformanceSales value={value} />)
  expect(html).toContain('>700<')
  expect(html).toContain('>350,000<')
  expect(html).toContain('1 234,56')
  expect(html).not.toContain('475')
  expect(html).not.toContain('900')
  expect(html).not.toContain('Прогноз продаж —')
  expect(html).not.toContain('Пока не оцениваем будущие возвраты')
  const unavailable = renderToStaticMarkup(
    <PerformanceSales
      value={{ ...value, cohort: null, observedSalesSpeed: null }}
    />,
  )
  expect(unavailable).not.toContain('>0 шт.')
})
