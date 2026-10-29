import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it } from 'vitest'
import {
  performanceFixture,
  netPerformanceFixture,
} from '../../../../tests/fixtures/salesPerformance'
import { PerformanceGrid } from './PerformanceGrid'

it.each(['period', 'cumulative'] as const)(
  'puts metric names and units in pinned row headers in %s mode',
  (mode) => {
    const first = performanceFixture.skus[0]?.points[0]
    if (first === undefined) throw new Error('Missing fixture')
    const data = {
      ...performanceFixture,
      skus: [
        {
          marketplaceSku: 'SKU-1',
          points: [
            { ...first, bucketFrom: '2026-07-01', bucketTo: '2026-07-01' },
            { ...first, bucketFrom: '2026-07-02', bucketTo: '2026-07-02' },
          ],
        },
      ],
    }
    const html = renderToStaticMarkup(
      <PerformanceGrid data={data} mode={mode} />,
    )
    const rows = [...html.matchAll(/<tr\b[^>]*>[\s\S]*?<\/tr>/g)].map(
      (match) => match[0],
    )
    const target = rows.find((row) =>
      /<th\b[^>]*scope="row"[^>]*>[^]*?Исходная цель/.test(row),
    )
    expect(target).toBeDefined()
    expect(target).toContain('sticky left-0')
    expect(target).toContain('шт.')
    const cells = [
      ...(target ?? '').matchAll(/<td\b[^>]*>([\s\S]*?)<\/td>/g),
    ].map((match) => match[1])
    expect(cells).toHaveLength(2)
    expect(cells).toEqual([
      mode === 'period' ? '1000' : '2000',
      mode === 'period' ? '1000' : '2000',
    ])
    expect(html.match(/Исходная цель/g)).toHaveLength(1)
    expect(html).toContain('Показатель')
  },
)

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
  expect(html).toContain('>700<')
  expect(html).toContain('Только эта страница SKU')
})

it('switches to server cumulative rather than adding visible period values', () => {
  const html = renderToStaticMarkup(
    <PerformanceGrid data={performanceFixture} mode="cumulative" />,
  )
  expect(html).toContain('>2000<')
  expect(html).toContain('Нет полных данных о заказах')
  expect(html).not.toContain('>700<')
})

it('keeps full net separate from observed, gross and the original goal', () => {
  const html = renderToStaticMarkup(
    <PerformanceGrid data={netPerformanceFixture} />,
  )
  expect(html).toContain('>70<')
  expect(html).toContain('>95<')
  expect(html).toContain('>90,125<')
  expect(html).toContain('123,45')
  expect(html).toContain('>1000<')
  expect(html).not.toContain('160,125')
})

it('keeps different currencies in separately labelled rows without rounding exact amounts', () => {
  const data = structuredClone(netPerformanceFixture)
  const sku = data.skus[0]
  const first = sku?.points[0]
  if (sku === undefined || first?.period.cohort == null)
    throw new Error('Missing net fixture')
  first.period.cohort.expectedNetRevenue = {
    minor: '9223372036854775807',
    currency: 'RUB',
    fractionDigits: 2,
  }
  sku.points.push({
    ...first,
    bucketFrom: '2026-07-03',
    bucketTo: '2026-07-03',
    period: {
      ...first.period,
      cohort: {
        ...first.period.cohort,
        expectedNetRevenue: { minor: '-1', currency: 'CLF', fractionDigits: 4 },
      },
    },
  })
  const html = renderToStaticMarkup(<PerformanceGrid data={data} />)
  const rows = [...html.matchAll(/<tr\b[^>]*>[\s\S]*?<\/tr>/g)].map(
    (match) => match[0],
  )
  const rub = rows.find(
    (row) => row.includes('Ожидаемая нетто-выручка') && row.includes(', р.'),
  )
  const clf = rows.find(
    (row) => row.includes('Ожидаемая нетто-выручка') && row.includes(', CLF'),
  )
  const cells = (row: string | undefined) =>
    [...(row ?? '').matchAll(/<td\b[^>]*>([\s\S]*?)<\/td>/g)].map(
      (match) => match[1],
    )
  expect(cells(rub)).toEqual(['92 233 720 368 547 758,07', '—'])
  expect(cells(clf)).toEqual(['—', '-0,0001'])
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
  expect(period).toContain('>0,333<')
  expect(period).toContain('>33,33<')
  expect(period).toContain('>-0,667<')
  expect(cumulative).toContain('>50<')
  expect(cumulative).toContain('>-1,000<')
  expect(cumulative).toContain('>0,250<')
  expect(period).toContain('0,001')
  expect(period).toContain('KWD')
  expect(cumulative).toContain('>1,000<')
  expect(cumulative).toContain('0,999')
  expect(cumulative).not.toContain('>0,333<')
  const hidden = renderToStaticMarkup(
    <PerformanceGrid data={netPerformanceFixture} mode="cumulative" />,
  )
  expect(hidden).toContain('Нет полных данных для нетто-прогноза')
  expect(hidden).not.toContain('>90,125<')
})
