import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import type { components } from '../../../api/schema'
import { http, server } from '../../../../tests/msw/server'
import { calendarQueryOptions } from './useSalesPlanCalendar'

type Page = components['schemas']['SalesPlanCalendarResponse']
const page = (version = 1): Page => ({
  planId: 'plan-a',
  version,
  status: 'draft',
  seasonStart: { kind: 'season_start', date: '2026-07-01' },
  seasonEnd: { kind: 'season_end', date: '2026-07-31' },
  entries: [],
  nextCursor: null,
})
const entry = (id: string): Page['entries'][number] => ({
  id,
  marketplaceSku: null,
  kind: 'sales',
  dateFrom: '2026-07-01',
  dateTo: '2026-07-31',
})

describe('complete calendar snapshots', () => {
  it('rejects interrupted, oversized and nonterminating snapshots without caching partial entries', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    for (const failure of [
      'network',
      'page-limit',
      'total-limit',
      'never-ending',
      'status',
      'wrong-plan',
    ] as const) {
      let calls = 0
      server.use(
        http.get(
          '/api/companies/{companyId}/sales-plans/{planId}/calendar',
          ({ response }) => {
            calls += 1
            if (failure === 'network' && calls === 2)
              return response(422).json({
                status: 422,
                code: 'invalid_calendar_request',
                message: 'Calendar changed.',
              })
            const count =
              failure === 'page-limit' ? 201 : calls === 3 ? 101 : 200
            return response(200).json({
              ...page(),
              planId: failure === 'wrong-plan' ? 'foreign' : 'plan-a',
              status:
                failure === 'status' && calls === 2 ? 'approved' : 'draft',
              entries: Array.from({ length: count }, (_, index) =>
                entry(String(calls * 1000 + index)),
              ),
              nextCursor:
                calls < 3 || failure === 'never-ending' ? String(calls) : null,
            })
          },
        ),
      )
      const client = new QueryClient({
        defaultOptions: { queries: { retry: false } },
      })
      const options = calendarQueryOptions('company-a', 'plan-a', 1)
      await expect(client.fetchQuery(options)).rejects.toThrow()
      expect(client.getQueryData(options.queryKey)).toBeUndefined()
      expect(calls).toBeLessThanOrEqual(3)
      client.clear()
    }
  })

  it('uses trusted header version for cache scope and refuses empty identifiers without requests', async () => {
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const empty = calendarQueryOptions('', 'plan-a', 1)
    expect(empty.enabled).toBe(false)
    await expect(client.fetchQuery(empty)).rejects.toThrow(
      'Нужны компания и план.',
    )
    expect(calendarQueryOptions('company-a', 'plan-a', 1).queryKey).not.toEqual(
      calendarQueryOptions('company-a', 'plan-a', 2).queryKey,
    )
    client.clear()
  })

  it('loads 200/200/100 once in scope and keeps other company cache separate', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    const cursors: (string | null)[] = []
    server.use(
      http.get(
        '/api/companies/{companyId}/sales-plans/{planId}/calendar',
        ({ request, params, response }) => {
          expect(params.companyId).toBe('company-a')
          expect(params.planId).toBe('plan-a')
          const search = new URL(request.url).searchParams
          expect(search.get('limit')).toBe('200')
          const cursor = search.get('cursor')
          cursors.push(cursor)
          const start = cursor === null ? 0 : cursor === 'second' ? 200 : 400
          const count = start === 400 ? 100 : 200
          return response(200).json({
            ...page(),
            entries: Array.from({ length: count }, (_, index) =>
              entry(String(start + index)),
            ),
            nextCursor: start === 0 ? 'second' : start === 200 ? 'third' : null,
          })
        },
      ),
    )
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    const other = calendarQueryOptions('company-b', 'plan-a', 1)
    client.setQueryData(other.queryKey, {
      ...page(),
      entries: [entry('foreign')],
    })
    const result = await client.fetchQuery(
      calendarQueryOptions('company-a', 'plan-a', 1),
    )
    expect(result.entries).toHaveLength(500)
    expect(new Set(result.entries.map((row) => row.id)).size).toBe(500)
    expect(cursors).toEqual([null, 'second', 'third'])
    expect(client.getQueryData(other.queryKey)).toEqual({
      ...page(),
      entries: [entry('foreign')],
    })
    client.clear()
  })

  it('never returns the first page when a later version changes or a cursor repeats', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    for (const failure of ['version', 'duplicate', 'loop'] as const) {
      server.use(
        http.get(
          '/api/companies/{companyId}/sales-plans/{planId}/calendar',
          ({ request, response }) => {
            const cursor = new URL(request.url).searchParams.get('cursor')
            if (cursor === null)
              return response(200).json({
                ...page(),
                entries: [entry('one')],
                nextCursor: 'second',
              })
            return response(200).json({
              ...page(failure === 'version' ? 2 : 1),
              entries: [entry(failure === 'duplicate' ? 'one' : 'two')],
              nextCursor: failure === 'loop' ? 'second' : null,
            })
          },
        ),
      )
      const client = new QueryClient({
        defaultOptions: { queries: { retry: false } },
      })
      const options = calendarQueryOptions('company-a', 'plan-a', 1)
      await expect(client.fetchQuery(options)).rejects.toThrow()
      expect(client.getQueryData(options.queryKey)).toBeUndefined()
      client.clear()
    }
  })
})
