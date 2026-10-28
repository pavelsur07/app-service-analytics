import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { MemoryRouter, Route, Routes } from 'react-router'
import { expect, it, vi } from 'vitest'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { http, server } from '../../../../tests/msw/server'
import { calendarQueryOptions } from '../model/useSalesPlanCalendar'
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

it('retains the calendar host when a trusted header reread fails', async () => {
  vi.stubGlobal('location', new URL('http://localhost'))
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, retryOnMount: false } },
  })
  const key = salesPlanQueryKey('one', 'plan', 'period', null)
  client.setQueryData(key, {
    plan: salesPlanFixture,
    groupBy: 'period',
    skus: [],
    nextCursor: null,
  })
  client.setQueryData(
    calendarQueryOptions('one', 'plan', salesPlanFixture.version).queryKey,
    {
      planId: 'plan',
      version: salesPlanFixture.version,
      status: 'draft',
      seasonStart: { kind: 'season_start', date: salesPlanFixture.dateFrom },
      seasonEnd: { kind: 'season_end', date: salesPlanFixture.dateTo },
      entries: [],
    },
  )
  server.use(
    http.get(
      '/api/companies/{companyId}/sales-plans/{planId}',
      ({ response }) =>
        response(404).json({
          status: 404,
          code: 'temporarily_unavailable',
          message: 'Перечитывание не удалось.',
        }),
    ),
  )
  await expect(
    client.fetchQuery({
      queryKey: key,
      queryFn: () =>
        createCompanyApiClient('one').get<
          components['schemas']['SalesPlanDetailResponse']
        >('/sales-plans/plan'),
    }),
  ).rejects.toThrow()
  const html = renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={['/companies/one/sales-plans/plan']}>
        <Routes>
          <Route
            path="/companies/:companyId/sales-plans/:planId"
            element={<SalesPlanPage />}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
  expect(html).toContain('Не удалось загрузить план')
  expect(html).toContain('Календарь плана')
  expect(html).toContain('Добавить событие')
  client.clear()
})
