import { QueryClient } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { expect, it, vi } from 'vitest'
import { http, server } from '../../../../tests/msw/server'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
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
  expect(html).toContain('950 шт.')
  expect(html).toContain('700 шт.')
  expect(html).toContain('350,000 шт./день')
  expect(html).toContain('95%')
  expect(html).toContain('900 шт.')
  expect(html).toContain('не является прогнозом итоговых продаж')
  const cumulative = renderToStaticMarkup(
    <PerformanceGrid data={data} mode="cumulative" />,
  )
  expect(cumulative).toContain('2000 шт.')
  expect(cumulative).toContain('Заказы загружены частично')
  expect(cumulative).not.toContain('700 шт.')
  client.clear()
})
