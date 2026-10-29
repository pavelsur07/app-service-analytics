import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import {
  performanceFixture,
  netPerformanceFixture,
} from '../../../../tests/fixtures/salesPerformance'
import { PerformanceGrid } from './PerformanceGrid'

it('renders real metrics inside a captioned keyboard-scrollable table', () => {
  const html = renderToStaticMarkup(
    <PerformanceGrid data={performanceFixture} />,
  )
  expect(html).toContain('<caption')
  expect(html).toContain('scope="col"')
  expect(html).toContain('scope="row"')
  expect(html).toContain('tabindex="0"')
  expect(html).toContain('SKU-1')
  expect(html).toContain('Исходная цель')
  expect(html).toContain('01.07–02.07')
  expect(html).toContain('700 шт.')
  expect(html).toContain('Только эта страница SKU')
})

it('switches to server cumulative rather than adding visible period values', () => {
  const html = renderToStaticMarkup(
    <PerformanceGrid data={performanceFixture} mode="cumulative" />,
  )
  expect(html).toContain('2000 шт.')
  expect(html).toContain('Нет полных данных о заказах')
  expect(html).not.toContain('700 шт.')
})

it('keeps full net separate from observed, gross and the original goal', () => {
  const html = renderToStaticMarkup(
    <PerformanceGrid data={netPerformanceFixture} />,
  )
  expect(html).toContain('70 шт.')
  expect(html).toContain('95 шт.')
  expect(html).toContain('90,125 шт.')
  expect(html).toContain('123,45')
  expect(html).toContain('1000 шт.')
  expect(html).not.toContain('160,125')
})

it('uses independent server period and cumulative net rather than visible sums', () => {
  const point = netPerformanceFixture.skus[0]?.points[0]
  if (point?.period.cohort == null) throw new Error('Missing net fixture')
  const data = {
    ...netPerformanceFixture,
    skus: [
      {
        marketplaceSku: 'SKU-1',
        points: [
          {
            ...point,
            period: {
              ...point.period,
              expectedGoalQuantity: '0.333',
              expectedAttainmentBps: 3333,
              expectedQuantityDeviation: '-0.667',
              expectedSpeed: '0.167',
              forecastUnavailableReason: null,
              cohort: {
                ...point.period.cohort,
                expectedNetSalesQuantity: '0.333',
                expectedNetRevenue: {
                  minor: '1',
                  currency: 'KWD',
                  fractionDigits: 3,
                },
              },
            },
            cumulative: {
              ...point.cumulative,
              expectedGoalQuantity: '1.000',
              expectedAttainmentBps: 5000,
              expectedQuantityDeviation: '-1.000',
              expectedSpeed: '0.250',
              forecastUnavailableReason: null,
              cohort: {
                ...point.period.cohort,
                expectedNetSalesQuantity: '1.000',
                expectedNetRevenue: {
                  minor: '999',
                  currency: 'KWD',
                  fractionDigits: 3,
                },
              },
            },
          },
        ],
      },
    ],
  }
  const period = renderToStaticMarkup(<PerformanceGrid data={data} />)
  const cumulative = renderToStaticMarkup(
    <PerformanceGrid data={data} mode="cumulative" />,
  )
  expect(period).toContain('0,333 шт.')
  expect(period).toContain('33,33%')
  expect(period).toContain('-0,667 шт.')
  expect(cumulative).toContain('50%')
  expect(cumulative).toContain('-1,000 шт.')
  expect(cumulative).toContain('0,250 шт./день')
  expect(period).toContain('0,001')
  expect(period).toContain('KWD')
  expect(cumulative).toContain('1,000 шт.')
  expect(cumulative).toContain('0,999')
  expect(cumulative).not.toContain('0,333 шт.')
  const hidden = renderToStaticMarkup(
    <PerformanceGrid data={netPerformanceFixture} mode="cumulative" />,
  )
  expect(hidden).toContain('Нет полных данных для нетто-прогноза')
  expect(hidden).not.toContain('90,125 шт.')
})
