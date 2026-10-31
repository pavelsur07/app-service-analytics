import { QueryClient } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it, vi } from 'vitest'
import { http, server } from '../../../../tests/msw/server'
import {
  performanceFixture,
  netPerformanceFixture,
} from '../../../../tests/fixtures/salesPerformance'
import { performanceQueryOptions } from '../model/useSalesPlanPerformance'
import { PerformanceGrid } from './PerformanceGrid'

it('renders the real grid from a typed network response without recomputing metrics', async () => {
  vi.stubGlobal('location', new URL('http://localhost'))
  server.use(
    http.get(
      '/api/companies/{companyId}/sales-plans/{planId}/performance',
      ({ response }) => response(200).json(performanceFixture),
    ),
  )
  const client = new QueryClient()
  const data = await client.fetchQuery(
    performanceQueryOptions(
      'one',
      'plan',
      {
        from: '2026-07-01',
        to: '2026-07-02',
        groupBy: 'period',
        cursor: null,
        limit: 20,
      },
      7,
    ),
  )
  const html = renderToStaticMarkup(<PerformanceGrid data={data} />)
  expect(html).toContain('>950<')
  expect(html).toContain('>700<')
  expect(html).toContain('>350,000<')
  expect(html).toContain('>95<')
  expect(html).toContain('>900<')
  expect(html).toContain('не является прогнозом итоговых продаж')
  const cumulative = renderToStaticMarkup(
    <PerformanceGrid data={data} mode="cumulative" />,
  )
  expect(cumulative).toContain('>2000<')
  expect(cumulative).toContain('Заказы загружены частично')
  expect(cumulative).not.toContain('>700<')
  client.clear()
})

it('keeps same-plan/SKU company net values isolated through the real client and grid', async () => {
  vi.stubGlobal('location', new URL('http://localhost'))
  let requests = 0
  server.use(
    http.get(
      '/api/companies/{companyId}/sales-plans/{planId}/performance',
      ({ params, request, response }) => {
        requests++
        const data = structuredClone(netPerformanceFixture)
        const goal = data.skus[0]?.points[0]?.period
        if (goal == null) throw new Error('Missing goal fixture')
        goal.expectedGoalQuantity =
          params.companyId === 'one' ? '905.125' : '8.000'
        goal.expectedAttainmentBps = params.companyId === 'one' ? 9051 : 80
        goal.expectedQuantityDeviation =
          params.companyId === 'one' ? '-94.875' : '-992.000'
        goal.expectedSpeed = params.companyId === 'one' ? '452.563' : '4.000'
        goal.forecastUnavailableReason = null
        const cohort = goal.cohort
        if (cohort == null) throw new Error('Missing net fixture')
        cohort.expectedNetSalesQuantity =
          params.companyId === 'one' ? '90.125' : '3.000'
        cohort.expectedNetRevenue =
          params.companyId === 'one'
            ? {
                minor: '9223372036854775807',
                currency: 'RUB',
                fractionDigits: 2,
              }
            : { minor: '-1', currency: 'CLF', fractionDigits: 4 }
        const query = new URL(request.url).searchParams
        expect(query.get('limit')).toBe('20')
        expect(query.has('epoch')).toBe(false)
        return response(200).json(data)
      },
    ),
  )
  const client = new QueryClient()
  const params = {
    from: '2026-07-01',
    to: '2026-07-02',
    groupBy: 'period',
    cursor: null,
    limit: 20,
  } as const
  try {
    for (const [company, expected, forbidden, amount] of [
      ['one', '>90,125<', '>3,000<', '92 233 720 368 547 758,07'],
      ['two', '>3,000<', '>90,125<', '-0,0001'],
    ]) {
      const data = await client.fetchQuery(
        performanceQueryOptions(company ?? '', 'plan', params, 7),
      )
      const html = renderToStaticMarkup(<PerformanceGrid data={data} />)
      expect(html).toContain(expected)
      expect(html).not.toContain(forbidden)
      expect(html).toContain(amount)
      expect(html).toContain(company === 'one' ? '>905,125<' : '>8,000<')
      expect(html).not.toContain(company === 'one' ? '>8,000<' : '>905,125<')
    }
    expect(requests).toBe(2)
    expect(client.getQueryCache().getAll()).toHaveLength(2)
  } finally {
    client.clear()
  }
})

it('renders independent zero, unavailable money and hidden cumulative prefix from typed network data', async () => {
  vi.stubGlobal('location', new URL('http://localhost'))
  const data = structuredClone(netPerformanceFixture)
  const cohort = data.skus[0]?.points[0]?.period.cohort
  if (cohort == null) throw new Error('Missing fixture')
  cohort.expectedNetSalesQuantity = '0.000'
  cohort.expectedNetRevenue = null
  cohort.netForecastMoneyUnavailableReason = 'money_price_missing'
  server.use(
    http.get(
      '/api/companies/{companyId}/sales-plans/{planId}/performance',
      ({ response }) => response(200).json(data),
    ),
  )
  const client = new QueryClient()
  try {
    const actual = await client.fetchQuery(
      performanceQueryOptions(
        'one',
        'plan',
        {
          from: '2026-07-01',
          to: '2026-07-02',
          groupBy: 'period',
          cursor: null,
          limit: 20,
        },
        7,
      ),
    )
    const html = renderToStaticMarkup(<PerformanceGrid data={actual} />)
    expect(html).toContain('>0,000<')
    expect(html).toContain('Нет цены исходной продажи')
    expect(html).toContain('>70<')
    const cumulative = renderToStaticMarkup(
      <PerformanceGrid data={actual} mode="cumulative" />,
    )
    expect(cumulative).not.toContain('>0,000<')
    expect(cumulative).toContain('Нет полных данных для прогноза продаж')
  } finally {
    client.clear()
  }
})
