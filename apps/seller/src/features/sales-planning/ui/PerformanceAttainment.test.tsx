import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { PerformanceAttainment as MetricRows } from './PerformanceAttainment'
import { performanceColumns } from '../../../../tests/fixtures/performanceColumns'
import type { components } from '../../../api/schema'

function PerformanceAttainment({
  value,
}: {
  value: components['schemas']['PerformanceValuesResponse']
}) {
  return <MetricRows columns={performanceColumns(value)} currency="RUB" />
}

it('uses server attainment and signed deviation, keeping zero targets undefined', () => {
  const value = performanceFixture.skus[0]?.points[0]?.period
  if (value === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(<PerformanceAttainment value={value} />)
  expect(html).toContain('>95<')
  expect(html).toContain('>-50<')
  expect(html).not.toContain('>90<')
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
  expect(zero).toContain('>950<')
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
  expect(partial).not.toContain('>0<')
})

it('displays all four literal server goal fields and keeps actual attainment separate', () => {
  const value = performanceFixture.skus[0]?.points[0]?.period
  if (value === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(
    <PerformanceAttainment
      value={{
        ...value,
        actualGoalQuantity: 700,
        attainmentBps: 7000,
        expectedGoalQuantity: '900.000',
        expectedAttainmentBps: 9000,
        expectedQuantityDeviation: '-100.000',
        expectedSpeed: '301.125',
        forecastUnavailableReason: null,
      }}
    />,
  )
  for (const text of [
    '>70<',
    '>90<',
    '>900,000<',
    '>-100,000<',
    '>301,125<',
    'Ожидаемое количество по метрике цели',
    'Ожидаемое отклонение от цели',
    'Ожидаемый темп метрики цели',
  ])
    expect(html).toContain(text)
  expect(html).not.toContain('1600')
  expect(html).not.toContain('Проверка качества')
})

it('preserves zero-plan quantity, signed deviation and server speed without inventing percent', () => {
  const value = performanceFixture.skus[0]?.points[0]?.period
  if (value === undefined) throw new Error('Missing fixture')
  const html = renderToStaticMarkup(
    <PerformanceAttainment
      value={{
        ...value,
        planQuantity: 0,
        attainmentBps: null,
        expectedGoalQuantity: '0.333',
        expectedAttainmentBps: null,
        expectedQuantityDeviation: '0.333',
        expectedSpeed: '0.063',
        forecastUnavailableReason: null,
      }}
    />,
  )
  expect(html).toContain('>0,333<')
  expect(html).toContain('>0,063<')
  expect(html).not.toContain('>0<')
})

it('formats the exact JS-safe expected bps and explains overflow or publication pending', () => {
  const value = performanceFixture.skus[0]?.points[0]?.period
  if (value === undefined) throw new Error('Missing fixture')
  const maximum = renderToStaticMarkup(
    <PerformanceAttainment
      value={{
        ...value,
        expectedAttainmentBps: 9007199254740991,
        forecastUnavailableReason: null,
      }}
    />,
  )
  expect(maximum).toContain('>90071992547409,91<')
  const pending = renderToStaticMarkup(<PerformanceAttainment value={value} />)
  expect(pending).toContain(
    'Публикация предварительного прогноза пока не включена',
  )
  const overflow = renderToStaticMarkup(
    <PerformanceAttainment
      value={{
        ...value,
        forecastUnavailableReason: 'goal_attainment_overflow',
      }}
    />,
  )
  expect(overflow).toContain(
    'Выполнение цели выходит за поддерживаемый диапазон',
  )
  expect(overflow).toContain('>95<')
})
