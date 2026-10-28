import { QueryClient } from '@tanstack/react-query'
import { HttpResponse } from 'msw'
import { expect, it, vi } from 'vitest'
import { http, server } from '../../../../tests/msw/server'
import { performanceFixture } from '../../../../tests/fixtures/salesPerformance'
import { salesPlanFixture } from '../../../../tests/fixtures/salesPlan'
import { createCompanyApiClient } from '../../../api/companyClient'
import type { components } from '../../../api/schema'
import { performanceQueryOptions } from '../model/useSalesPlanPerformance'
import { recoverPerformance } from './performanceRecovery'

it('retries failed performance after an unchanged header version is reread', async () => {
  vi.stubGlobal('location', new URL('http://localhost'))
  let attempts = 0
  server.use(
    http.get(
      '/api/companies/{companyId}/sales-plans/{planId}/performance',
      ({ response }) => {
        attempts++
        return attempts === 1
          ? HttpResponse.error()
          : response(200).json(performanceFixture)
      },
    ),
    http.get(
      '/api/companies/{companyId}/sales-plans/{planId}',
      ({ response }) =>
        response(200).json({
          plan: salesPlanFixture,
          groupBy: 'period',
          skus: [],
          nextCursor: null,
        }),
    ),
  )
  const client = new QueryClient()
  const options = performanceQueryOptions(
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
  )
  await expect(client.fetchQuery(options)).rejects.toThrow()
  await recoverPerformance(
    () =>
      createCompanyApiClient('one').get<
        components['schemas']['SalesPlanDetailResponse']
      >('/sales-plans/plan'),
    () => client.fetchQuery(options),
  )
  expect(attempts).toBe(2)
  expect(client.getQueryData(options.queryKey)).toEqual(performanceFixture)
  client.clear()
})
