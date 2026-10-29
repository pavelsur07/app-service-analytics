import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter } from 'react-router'
import { expect, it, vi } from 'vitest'
import {
  performanceFixture,
  netPerformanceFixture,
} from '../../../../tests/fixtures/salesPerformance'
import { salesPlanFixture } from '../../../../tests/fixtures/salesPlan'
import { writePerformanceSearch } from '../lib/performanceSearch'
import { performanceQueryOptions } from '../model/useSalesPlanPerformance'
import { SalesPlanPerformance } from './SalesPlanPerformance'
import { http, server } from '../../../../tests/msw/server'

it('reads shared URL period/cumulative/page and resets a stale header cursor before querying', () => {
  const originalContext = { companyId: 'one', plan: salesPlanFixture }
  const applied = {
    from: '2026-07-01',
    to: '2026-07-02',
    groupBy: 'period',
  } as const
  const search = writePerformanceSearch(
    new URLSearchParams(),
    { applied, mode: 'cumulative', cursor: 'page-2' },
    originalContext,
  )
  for (const version of [7, 8]) {
    const client = new QueryClient()
    const cursor = version === 7 ? 'page-2' : null
    client.setQueryData(
      performanceQueryOptions(
        'one',
        'plan',
        { ...applied, cursor, limit: 20 },
        version,
      ).queryKey,
      performanceFixture,
    )
    const html = renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <MemoryRouter initialEntries={[`/?${search.toString()}`]}>
          <SalesPlanPerformance
            companyId="one"
            plan={{ ...salesPlanFixture, version }}
            onReread={async () => undefined}
          />
        </MemoryRouter>
      </QueryClientProvider>,
    )
    expect(html).toContain('2000 шт.')
    expect(html).toContain('Окно: 2026-07-01')
    expect(html).toContain('Накопительно')
    expect(html.match(/Методика нетто-прогноза/g)).toHaveLength(1)
    expect(html.indexOf('Методика нетто-прогноза')).toBeLessThan(
      html.indexOf('<table'),
    )
    if (version === 7) expect(html).toContain('К первым SKU')
    client.clear()
  }
})

const applied = {
  from: '2026-07-01',
  to: '2026-07-02',
  groupBy: 'period',
} as const
function page(
  client: QueryClient,
  companyId = 'one',
  version = 7,
  cursor: string | null = null,
) {
  const plan = { ...salesPlanFixture, version }
  const search = writePerformanceSearch(
    new URLSearchParams(),
    { applied, mode: 'period', cursor },
    { companyId, plan },
  )
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[`/?${search.toString()}`]}>
        <SalesPlanPerformance
          companyId={companyId}
          plan={plan}
          onReread={async () => undefined}
        />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

it('hides cached net while a different company/version/cursor is loading', () => {
  const client = new QueryClient()
  client.setQueryData(
    performanceQueryOptions(
      'one',
      'plan',
      { ...applied, cursor: null, limit: 20 },
      7,
    ).queryKey,
    netPerformanceFixture,
  )
  expect(page(client)).toContain('90,125 шт.')
  for (const [company, version, cursor] of [
    ['two', 7, null],
    ['one', 8, null],
    ['one', 7, 'next'],
  ] as const) {
    const html = page(client, company, version, cursor)
    expect(html).toContain('Загружаем сравнение')
    expect(html).not.toContain('90,125 шт.')
    expect(html).not.toContain('Методика нетто-прогноза')
  }
  client.clear()
})

it.each([403, 404, 409] as const)(
  'hides cached net after typed %s and offers recovery',
  async (status) => {
    vi.stubGlobal('location', new URL('http://localhost'))
    const client = new QueryClient({
      defaultOptions: { queries: { retryOnMount: false } },
    })
    const options = performanceQueryOptions(
      'one',
      'plan',
      { ...applied, cursor: null, limit: 20 },
      7,
    )
    client.setQueryData(options.queryKey, netPerformanceFixture)
    server.use(
      http.get(
        '/api/companies/{companyId}/sales-plans/{planId}/performance',
        ({ response }) =>
          response(status).json({
            status,
            code:
              status === 409
                ? 'sales_plan_no_approved_baseline'
                : 'access_unavailable',
            message: 'Unavailable',
          }),
      ),
    )
    await expect(client.fetchQuery(options)).rejects.toMatchObject({ status })
    const html = page(client)
    expect(html).toContain('Перечитать план')
    expect(html).not.toContain('90,125 шт.')
    expect(html).not.toContain('Методика нетто-прогноза')
    server.use(
      http.get(
        '/api/companies/{companyId}/sales-plans/{planId}/performance',
        ({ response }) => response(200).json(performanceFixture),
      ),
    )
    await client.fetchQuery(options)
    expect(page(client)).toContain(
      'Проверка качества прогноза ещё не завершена',
    )
    expect(page(client)).not.toContain('90,125 шт.')
    client.clear()
  },
)
