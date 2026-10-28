import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it, vi } from 'vitest'
import { http, server } from '../../../../tests/msw/server'
import { lifecycleMutationOptions } from './useSalesPlanLifecycle'
import { lifecycleFailure } from '../lib/lifecycleFailure'
import { salesPlanningSectionKey } from './salesPlanningQueryKeys'

it('sends trusted header version and invalidates only this company including comparison', async () => {
  vi.stubGlobal('location', new URL('http://localhost'))
  server.use(
    http.post(
      '/api/companies/{companyId}/sales-plans/{planId}/approve',
      async ({ request, response }) => {
        expect(await request.json()).toEqual({ version: 7 })
        return response(200).json({
          id: 'plan',
          version: 8,
          status: 'approved',
        })
      },
    ),
  )
  const client = new QueryClient()
  const own = [...salesPlanningSectionKey('one'), 'performance']
  const foreign = [...salesPlanningSectionKey('two'), 'performance']
  client.setQueryData(own, { secret: 'one' })
  client.setQueryData(foreign, { secret: 'two' })
  const mutation = client
    .getMutationCache()
    .build(client, lifecycleMutationOptions(client, 'one', 'plan'))
  await mutation.execute({ kind: 'approve', version: 7 })
  expect(client.getQueryState(own)?.isInvalidated).toBe(true)
  expect(client.getQueryState(foreign)?.isInvalidated).toBe(false)
  client.clear()
})

describe('metric mutation', () => {
  it('uses explicit generated metric and exposes 409 without overwriting draft values', async () => {
    vi.stubGlobal('location', new URL('http://localhost'))
    server.use(
      http.put(
        '/api/companies/{companyId}/sales-plans/{planId}/metric',
        async ({ request, response }) => {
          expect(await request.json()).toEqual({
            version: 7,
            metric: 'retained_sales',
          })
          return response(409).json({
            status: 409,
            code: 'version_conflict',
            message: 'Conflict',
          })
        },
      ),
    )
    const client = new QueryClient()
    const input = {
      kind: 'metric',
      version: 7,
      metric: 'retained_sales',
    } as const
    const mutation = client
      .getMutationCache()
      .build(client, lifecycleMutationOptions(client, 'one', 'plan'))
    await expect(mutation.execute(input)).rejects.toMatchObject({ status: 409 })
    expect(input).toEqual({
      kind: 'metric',
      version: 7,
      metric: 'retained_sales',
    })
    client.clear()
  })
})

it('preserves a typed occupied-day409 reason through the real API parser', async () => {
  vi.stubGlobal('location', new URL('http://localhost'))
  server.use(
    http.post(
      '/api/companies/{companyId}/sales-plans/{planId}/approve',
      ({ response }) =>
        response(409).json({
          status: 409,
          code: 'sales_plan_day_conflict',
          message: 'Дни уже заняты утверждённым планом.',
          conflicts: [{ marketplaceSku: 'SKU-1', date: '2026-07-01' }],
        }),
    ),
  )
  const client = new QueryClient()
  const mutation = client
    .getMutationCache()
    .build(client, lifecycleMutationOptions(client, 'one', 'plan'))
  const error: unknown = await mutation
    .execute({ kind: 'approve', version: 7 })
    .catch((caught: unknown) => caught)
  const failure = lifecycleFailure(error)
  expect(failure.versionConflict).toBe(false)
  expect(failure.message).toContain('Дни уже заняты')
  expect(failure.message).toContain('Измените период')
  client.clear()
})
