// Standalone, sequential, real nginx/php-fpm HTTP measurements. No browser mocks.
import assert from 'node:assert/strict'
import { readFile, writeFile } from 'node:fs/promises'
import { performance } from 'node:perf_hooks'
import { request } from '@playwright/test'

if (process.env.CONWIX_BENCHMARK_PLANNING !== '1') {
  throw new Error('Only explicitly enabled local planning benchmark is allowed.')
}
const baseURL = 'http://app.conwix.internal'
const directory = new URL('../api/var/benchmark-sales-planning/', import.meta.url)
const state = JSON.parse(await readFile(new URL('state.json', directory), 'utf8'))
assert.match(state.runId, /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
assert.equal(state.email, `benchmark-planning+${state.runId}@example.com`)
assert.equal(state.plans.length, 2)
const context = await request.newContext({
  baseURL,
  extraHTTPHeaders: { 'X-Conwix-Request': '1' },
  timeout: 120_000,
})
const scenarios = []
let failure
try {
  const login = await context.post('/api/auth/login', {
    data: { email: state.email, password: state.password },
  })
  assert.equal(login.status(), 200, 'Synthetic actor must authenticate')
  await login.dispose()
  for (const plan of state.plans) {
    const resource = `/api/companies/${state.companyId}/sales-plans/${plan.id}`
    const headerResponse = await context.get(resource)
    assert.equal(headerResponse.status(), 200)
    const header = await headerResponse.json()
    await headerResponse.dispose()
    if (header.plan.status === 'draft') {
      const approval = await context.post(`${resource}/approve`, {
        data: { version: header.plan.version },
      })
      assert.equal(approval.status(), 200, 'Approve the actual draft through HTTP')
      await approval.dispose()
    } else {
      assert.equal(header.plan.status, 'approved')
    }
  }
  for (const plan of state.plans) {
    const groups = plan.days === 31 ? ['day'] : ['day', 'week', 'month', 'period']
    for (const groupBy of groups) {
      const last31 = new Date(`${plan.to}T00:00:00Z`)
      last31.setUTCDate(last31.getUTCDate() - 30)
      const from = groupBy === 'day' ? last31.toISOString().slice(0, 10) : plan.from
      for (const limit of [1, 50]) {
        for (let repeat = 0; repeat < 4; repeat += 1) {
          const started = performance.now()
          const response = await context.get(
            `/api/companies/${state.companyId}/sales-plans/${plan.id}/performance`,
            { params: { from, to: plan.to, groupBy, limit } },
          )
          const body = await response.body()
          let payload
          let parseFailure
          try {
            payload = JSON.parse(body.toString('utf8'))
          } catch (error) {
            parseFailure = error
          }
          const elapsedMs = performance.now() - started
          const measurement = {
            scenario: `${plan.days}-${groupBy}-${limit}`,
            repeat,
            phase: repeat === 0 ? 'first-scenario-request' : 'warm',
            status: response.status(),
            elapsedMs,
            responseBytes: body.length,
            serverTiming: response.headers()['server-timing'] ?? null,
            profilerToken: response.headers()['x-debug-token'] ?? null,
          }
          scenarios.push(measurement)
          assert.equal(response.status(), 200)
          if (parseFailure) throw parseFailure
          assert.equal(payload.baselinePlanId, plan.id)
          assert.equal(payload.metric, 'retained_sales')
          assert.equal(payload.accumulationFrom, plan.from)
          assert.equal(payload.ordersCoverage, 'complete')
          assert.equal(payload.skus.length, limit)
          assert.equal(payload.groupBy, groupBy)
          for (const sku of payload.skus) {
            assert.ok(Number(sku.marketplaceSku) >= plan.skuBase)
            assert.ok(Number(sku.marketplaceSku) < plan.skuBase + 50)
            assert.ok(sku.points.length > 0 && sku.points.length <= 31)
            for (const point of sku.points) {
              assert.equal(point.periodCoverage, 'complete')
              assert.equal(point.cumulativeCoverage, 'complete')
              assert.ok(Number.isSafeInteger(point.period.planRevenueRrp.minor))
              assert.ok(Number.isSafeInteger(point.period.cohort.observedRevenue.minor))
              assert.equal(point.period.cohort.observedRevenue.currency, 'RUB')
              if (plan.days === 184 && groupBy === 'day') {
                assert.ok(point.cumulative.planQuantity > point.period.planQuantity)
                assert.ok(point.cumulative.cohort.rawOrderedQuantity > point.period.cohort.rawOrderedQuantity)
              }
            }
          }
          await response.dispose()
          console.log(`${measurement.scenario} ${measurement.phase} ${elapsedMs.toFixed(1)}ms ${body.length}bytes`)
        }
      }
    }
  }
} catch (error) {
  failure = error
} finally {
  await context.dispose()
  const report = {
    at: new Date().toISOString(),
    baseURL,
    fixture: state.fixture,
    method: 'Serial HTTP via nginx/php-fpm; includes body read and JSON.parse; first request is not reset PostgreSQL buffers or OPcache; no concurrent EXPLAIN.',
    budgetMs: 2000,
    scenarios,
    failure: failure instanceof Error ? failure.message : null,
  }
  await writeFile(new URL('http-summary.json', directory), JSON.stringify(report, null, 2))
}
if (failure) throw failure
assert.ok(scenarios.length === 40, 'All scenarios/repetitions must run')
assert.ok(scenarios.every((sample) => sample.elapsedMs < 2000), 'Measured HTTP budget exceeded; inspect saved report')
