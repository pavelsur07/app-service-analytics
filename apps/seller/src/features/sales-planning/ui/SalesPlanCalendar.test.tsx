import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it, vi } from 'vitest'
import type { components } from '../../../api/schema'
import { http, server } from '../../../../tests/msw/server'
import { salesPlanFixture } from '../../../../tests/fixtures/salesPlan'
import { calendarQueryOptions } from '../model/useSalesPlanCalendar'
import { SalesPlanCalendar } from './SalesPlanCalendar'

type Page = components['schemas']['SalesPlanCalendarResponse']
const snapshot: Page = {
  planId: salesPlanFixture.id,
  version: salesPlanFixture.version + 1,
  status: 'draft',
  seasonStart: { kind: 'season_start', date: '2026-07-01' },
  seasonEnd: { kind: 'season_end', date: '2026-07-31' },
  entries: [
    {
      id: 'sale',
      marketplaceSku: null,
      kind: 'sales',
      dateFrom: '2026-07-01',
      dateTo: '2026-07-31',
    },
    {
      id: 'supply',
      marketplaceSku: 'sku-a',
      kind: 'replenishment',
      dateFrom: '2026-07-01',
      dateTo: '2026-07-01',
    },
    {
      id: 'stop',
      marketplaceSku: 'sku-a',
      kind: 'replenishment_stop',
      dateFrom: '2026-07-20',
      dateTo: '2026-07-20',
    },
  ],
  nextCursor: null,
}
function render(client: QueryClient, companyId = 'one') {
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <SalesPlanCalendar companyId={companyId} plan={salesPlanFixture} />
    </QueryClientProvider>,
  )
}

describe('real calendar UI snapshot readiness', () => {
  it('uses newer calendar draft status/version and renders independent events with computed boundaries', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    server.use(
      http.get(
        '/api/companies/{companyId}/sales-plans/{planId}/calendar',
        ({ response }) => response(200).json(snapshot),
      ),
    )
    const client = new QueryClient()
    await client.fetchQuery(
      calendarQueryOptions(
        'one',
        salesPlanFixture.id,
        salesPlanFixture.version,
      ),
    )
    const html = render(client)
    expect(html).toContain('Начало сезона')
    expect(html).toContain('Конец сезона')
    expect(html).toContain('2026-07-31')
    expect(html).toContain('Продажи')
    expect(html).toContain('Подсорт')
    expect(html).toContain('Стоп подсорт')
    expect(html).toContain('Весь план')
    expect(html).toContain('Добавить событие')
    expect(html).toContain('Изменить событие 1')
    expect(html).toContain('Удалить событие 1')
    expect(html).toContain('не останавливает продажи')
    expect(render(client, 'two')).not.toContain('sku-a')
    client.clear()
  })

  it('keeps approved and historical calendars readonly even when the page header is draft', () => {
    for (const status of [
      'approved',
      'cancelled',
      'superseded',
      'discarded',
    ] as const) {
      const client = new QueryClient()
      client.setQueryData(
        calendarQueryOptions(
          'one',
          salesPlanFixture.id,
          salesPlanFixture.version,
        ).queryKey,
        { ...snapshot, status },
      )
      const html = render(client)
      expect(html).toContain('Только просмотр')
      expect(html).not.toContain('Добавить событие')
      expect(html).not.toContain('Изменить событие')
      expect(html).not.toContain('Удалить событие')
      expect(html).not.toContain('Сохранить календарь')
      client.clear()
    }
  })

  it('shows no save action after a failed later page and provides an explicit reread', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    let puts = 0
    server.use(
      http.get(
        '/api/companies/{companyId}/sales-plans/{planId}/calendar',
        ({ request, response }) => {
          if (new URL(request.url).searchParams.has('cursor'))
            return response(422).json({
              status: 422,
              code: 'invalid_calendar_request',
              message: 'Снимок изменился.',
            })
          return response(200).json({ ...snapshot, nextCursor: 'later' })
        },
      ),
      http.put(
        '/api/companies/{companyId}/sales-plans/{planId}/calendar',
        ({ response }) => {
          puts += 1
          return response(200).json({ version: 100 })
        },
      ),
    )
    const client = new QueryClient()
    await expect(
      client.fetchQuery(
        calendarQueryOptions(
          'one',
          salesPlanFixture.id,
          salesPlanFixture.version,
        ),
      ),
    ).rejects.toThrow()
    const html = render(client)
    expect(html).toContain('Не удалось загрузить календарь целиком')
    expect(html).toContain('Перечитать календарь')
    expect(html).not.toContain('Добавить событие')
    expect(html).not.toContain('Сохранить календарь')
    expect(puts).toBe(0)
    client.clear()
  })
})

it('keeps the full 500-entry snapshot while rendering only a bounded accessible DOM window', () => {
  const client = new QueryClient()
  const key = calendarQueryOptions(
    'one',
    salesPlanFixture.id,
    salesPlanFixture.version,
  ).queryKey
  const entries = Array.from({ length: 500 }, (_, index) => ({
    id: `entry-${index}`,
    marketplaceSku: `sku-${index}`,
    kind: 'sales' as const,
    dateFrom: '2026-07-01',
    dateTo: '2026-07-31',
  }))
  client.setQueryData(key, { ...snapshot, entries })
  const html = render(client)
  expect(html).toContain('aria-rowcount="501"')
  expect(html).toContain('aria-rowindex="2"')
  expect(
    (html.match(/aria-label="Изменить событие /g) ?? []).length,
  ).toBeLessThanOrEqual(10)
  expect(html).not.toContain('sku-499')
  expect(html).toContain('Home')
  expect(client.getQueryData<Page>(key)?.entries).toHaveLength(500)
  client.clear()
})

it('waits for the complete refreshed calendar before starting any new local draft', async () => {
  vi.stubGlobal('location', new URL('http://localhost'))
  const client = new QueryClient({
    defaultOptions: { queries: { staleTime: Infinity } },
  })
  const options = calendarQueryOptions(
    'one',
    salesPlanFixture.id,
    salesPlanFixture.version,
  )
  client.setQueryData(options.queryKey, { ...snapshot, version: 2 })
  let release: () => void = () => undefined
  let markStarted: () => void = () => undefined
  const gate = new Promise<void>((resolve) => {
    release = resolve
  })
  const started = new Promise<void>((resolve) => {
    markStarted = resolve
  })
  server.use(
    http.get(
      '/api/companies/{companyId}/sales-plans/{planId}/calendar',
      async ({ response }) => {
        markStarted()
        await gate
        return response(200).json({ ...snapshot, version: 3 })
      },
    ),
  )
  const pending = client.fetchQuery({ ...options, staleTime: 0 })
  try {
    await started
    const html = render(client)
    const add = html.match(/<button[^>]*>Добавить событие<\/button>/)?.[0]
    expect(add).toContain('disabled=""')
    for (const action of ['Изменить', 'Удалить']) {
      const button = html.match(
        new RegExp(`<button(?=[^>]*aria-label="${action} событие 1")[^>]*>`),
      )?.[0]
      expect(button).toContain('disabled=""')
    }
    release()
    await pending
    const fresh = render(client)
    expect(fresh).toContain('календаря: 3')
    expect(
      fresh.match(/<button[^>]*>Добавить событие<\/button>/)?.[0],
    ).not.toContain('disabled=""')
  } finally {
    release()
    await pending.catch(() => undefined)
    client.clear()
  }
})
