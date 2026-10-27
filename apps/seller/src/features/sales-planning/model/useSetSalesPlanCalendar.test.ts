import { MutationObserver, QueryClient } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import { ApiError } from '../../../api/ApiError'
import { http, server } from '../../../../tests/msw/server'
import { salesPlanQueryKey } from './salesPlanningQueryKeys'
import { calendarQueryOptions } from './useSalesPlanCalendar'
import { calendarMutationOptions } from './useSetSalesPlanCalendar'

describe('calendar snapshot mutations', () => {
  it('sends the full 500 entries with the captured version and invalidates only its company', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    const entries = Array.from({ length: 500 }, (_, index) => ({
      id: String(index),
      marketplaceSku: null,
      kind: 'sales' as const,
      dateFrom: '2026-07-01',
      dateTo: '2026-07-31',
    }))
    server.use(
      http.put(
        '/api/companies/{companyId}/sales-plans/{planId}/calendar',
        async ({ params, request, response }) => {
          expect(params.companyId).toBe('company-a')
          expect(params.planId).toBe('plan-a')
          expect(await request.json()).toEqual({ version: 4, entries })
          return response(200).json({ version: 5 })
        },
      ),
    )
    const client = new QueryClient()
    const own = salesPlanQueryKey('company-a', 'plan-a', 'day', null)
    const calendar = calendarQueryOptions('company-a', 'plan-a', 4).queryKey
    const foreign = salesPlanQueryKey('company-b', 'plan-a', 'day', null)
    for (const key of [own, calendar, foreign])
      client.setQueryData(key, 'cached')
    const observer = new MutationObserver(
      client,
      calendarMutationOptions(client, 'company-a', 'plan-a'),
    )
    await expect(observer.mutate({ version: 4, entries })).resolves.toEqual({
      version: 5,
    })
    expect(client.getQueryState(own)?.isInvalidated).toBe(true)
    expect(client.getQueryState(calendar)?.isInvalidated).toBe(true)
    expect(client.getQueryState(foreign)?.isInvalidated).toBe(false)
    client.clear()
  })

  it('keeps a typed conflict for the form and never silently retries with a newer version', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    let calls = 0
    server.use(
      http.put(
        '/api/companies/{companyId}/sales-plans/{planId}/calendar',
        async ({ request, response }) => {
          calls += 1
          expect(await request.json()).toEqual({ version: 4, entries: [] })
          return response(409).json({
            status: 409,
            code: 'version_conflict',
            message: 'План изменился.',
          })
        },
      ),
    )
    const client = new QueryClient()
    const observer = new MutationObserver(
      client,
      calendarMutationOptions(client, 'company-a', 'plan-a'),
    )
    await expect(
      observer.mutate({ version: 4, entries: [] }),
    ).rejects.toMatchObject(
      new ApiError(409, 'version_conflict', 'План изменился.'),
    )
    expect(calls).toBe(1)
    const empty = new MutationObserver(
      client,
      calendarMutationOptions(client, '', 'plan-a'),
    )
    await expect(empty.mutate({ version: 4, entries: [] })).rejects.toThrow(
      'Нужны компания и план.',
    )
    expect(calls).toBe(1)
    client.clear()
  })
})
