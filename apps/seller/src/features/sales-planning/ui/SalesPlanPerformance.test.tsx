import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter } from 'react-router'
import { expect, it } from 'vitest'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { salesPlanFixture } from '../../../../tests/fixtures/salesPlan'
import { writePerformanceSearch } from '../lib/performanceSearch'
import { performanceQueryOptions } from '../model/useSalesPlanPerformance'
import { SalesPlanPerformance } from './SalesPlanPerformance'

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
    if (version === 7) expect(html).toContain('К первым SKU')
    client.clear()
  }
})
