import {
  QueryClient,
  QueryClientProvider,
  QueryObserver,
  type QueryObserverOptions,
} from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import { http, server } from '../../../../tests/msw/server'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import type { components } from '../../../api/schema'
import {
  performanceQueryOptions,
  useSalesPlanPerformance,
} from './useSalesPlanPerformance'

const params = {
  from: '2026-07-01',
  to: '2026-07-02',
  groupBy: 'period',
  cursor: null,
  limit: 20,
} as const

describe('company performance query', () => {
  it('uses the company client, all scope parameters and separate caches', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    server.use(
      http.get(
        '/api/companies/{companyId}/sales-plans/{planId}/performance',
        ({ params: path, request, response }) => {
          expect(path.companyId).toBe('one')
          expect(path.planId).toBe('plan')
          const query = new URL(request.url).searchParams
          expect(Object.fromEntries(query)).toEqual({
            from: '2026-07-01',
            to: '2026-07-02',
            groupBy: 'period',
            limit: '20',
          })
          return response(200).json(performanceFixture)
        },
      ),
    )
    const client = new QueryClient()
    const one = performanceQueryOptions('one', 'plan', params, 1)
    await expect(client.fetchQuery(one)).resolves.toEqual(performanceFixture)
    expect(
      client.getQueryData(
        performanceQueryOptions('two', 'plan', params, 1).queryKey,
      ),
    ).toBeUndefined()
    expect(one.queryKey).not.toEqual(
      performanceQueryOptions('one', 'plan', { ...params, cursor: 'opaque' }, 1)
        .queryKey,
    )
    expect(one.queryKey).not.toEqual(
      performanceQueryOptions('one', 'plan', params, 2).queryKey,
    )
    client.clear()
  })

  it('never requests with an empty company and preserves typed deliberate errors', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    const client = new QueryClient()
    expect(performanceQueryOptions('', 'plan', params, 1).enabled).toBe(false)
    await expect(
      client.fetchQuery(performanceQueryOptions('', 'plan', params, 1)),
    ).rejects.toThrow()
    server.use(
      http.get(
        '/api/companies/{companyId}/sales-plans/{planId}/performance',
        ({ response }) =>
          response(409).json({
            status: 409,
            code: 'sales_plan_no_approved_baseline',
            message: 'No baseline',
          }),
      ),
    )
    await expect(
      client.fetchQuery(performanceQueryOptions('one', 'plan', params, 1)),
    ).rejects.toMatchObject({
      status: 409,
      code: 'sales_plan_no_approved_baseline',
    })
    client.clear()
  })
})

describe('actual performance hook enablement, observed without a DOM', () => {
  it.each([
    ['', 'plan', true, 0],
    ['one', '', true, 0],
    ['one', 'plan', false, 0],
    ['one', 'plan', true, 1],
  ] as const)(
    'company=%s plan=%s enabled=%s sends %s requests',
    async (companyId, planId, enabled, expected) => {
      vi.stubGlobal('location', new URL('http://localhost'))
      let requests = 0
      server.use(
        http.get(
          '/api/companies/{companyId}/sales-plans/{planId}/performance',
          ({ response }) => {
            requests++
            return response(200).json(performanceFixture)
          },
        ),
      )
      const client = new QueryClient()
      // Observe public options passed by the real useQuery; preserve its implementation.
      const observedClient: {
        defaultQueryOptions: typeof client.defaultQueryOptions<
          components['schemas']['SalesPlanPerformanceResponse']
        >
      } = client
      const spy = vi.spyOn(observedClient, 'defaultQueryOptions')
      function Probe() {
        const query = useSalesPlanPerformance(
          companyId,
          planId,
          params,
          7,
          enabled,
        )
        return createElement('output', null, String(query.isEnabled))
      }
      const markup = renderToStaticMarkup(
        createElement(QueryClientProvider, { client }, createElement(Probe)),
      )
      expect(markup).toContain(expected === 1 ? '>true<' : '>false<')
      const captured:
        | QueryObserverOptions<
            components['schemas']['SalesPlanPerformanceResponse']
          >
        | undefined = spy.mock.calls.find(
        ([options]) =>
          options.queryKey[0] === 'company' &&
          options.queryKey[1] === companyId &&
          options.queryKey.includes('performance'),
      )?.[0]
      if (captured === undefined)
        throw new Error('Real hook options were not observed')
      const observer = new QueryObserver(client, captured)
      const unsubscribe = observer.subscribe(() => undefined)
      try {
        if (expected === 1)
          await vi.waitFor(() =>
            expect(observer.getCurrentResult().isSuccess).toBe(true),
          )
        else await new Promise((resolve) => setTimeout(resolve, 25))
        expect(requests).toBe(expected)
        expect(observer.getCurrentResult().isEnabled).toBe(expected === 1)
        expect(captured.queryKey).toEqual([
          'company',
          companyId,
          'sales-planning',
          'performance',
          { planId, ...params, headerVersion: 7 },
        ])
      } finally {
        unsubscribe()
        observer.destroy()
        client.clear()
        spy.mockRestore()
      }
    },
  )
})
