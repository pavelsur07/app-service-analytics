import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter, Route, Routes } from 'react-router'
import { expect, it } from 'vitest'
import { salesPlanFixture } from '../../../../tests/fixtures/salesPlan'
import { salesPlanQueryKey } from '../model/salesPlanningQueryKeys'
import { SalesPlanPage } from './SalesPlanPage'

function render(
  status: string,
  company = 'one',
  cachedCompany = company,
  search = '',
  versionNumber = 1,
) {
  const client = new QueryClient()
  client.setQueryData(
    salesPlanQueryKey(cachedCompany, 'plan', 'period', null),
    {
      plan: { ...salesPlanFixture, status, versionNumber },
      groupBy: 'period',
      skus: [],
      nextCursor: null,
    },
  )
  const html = renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <MemoryRouter
        initialEntries={[`/companies/${company}/sales-plans/plan${search}`]}
      >
        <Routes>
          <Route
            path="/companies/:companyId/sales-plans/:planId"
            element={<SalesPlanPage />}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  const queries = client
    .getQueryCache()
    .getAll()
    .map((query) => query.queryKey)
  client.clear()
  return { html, queries }
}

it('defaults approved to comparison while retaining a truly hidden editor subtree', () => {
  const { html } = render('approved')
  expect(html).toContain('Сравнение с исходной целью сезона')
  expect(html).toContain('hidden="" class="hidden"')
  expect(html).toContain('Штуки текущей версии')
})

it('keeps first drafts and closed plans out of performance requests', () => {
  for (const status of ['draft', 'cancelled', 'superseded', 'discarded']) {
    const { html, queries } = render(status)
    expect(html).not.toContain('Сравнение с исходной целью сезона')
    expect(queries.some((key) => key.includes('performance'))).toBe(false)
  }
  expect(render('draft', 'two', 'one').html).not.toContain('Сезон')
})

it('opens revision comparison when shared URL selects that view', () => {
  const { html } = render(
    'draft',
    'one',
    'one',
    '?view=compare&from=2026-07-01&to=2026-07-02&groupBy=period&mode=cumulative',
    2,
  )
  expect(html).toContain('Сравнение с исходной целью сезона')
})
