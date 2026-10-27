import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import { http, server } from '../../../../tests/msw/server'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { performanceQueryOptions } from './useSalesPlanPerformance'

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
