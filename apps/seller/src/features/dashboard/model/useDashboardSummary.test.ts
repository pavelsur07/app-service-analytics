import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiGet } from '../../../api/client'
import { http, server } from '../../../../tests/msw/server'
import {
  dashboardSummaryPath,
  dashboardSummaryQueryKey,
  type DashboardSummaryParams,
  previousOfSameCompany,
  type DashboardSummaryResponse,
} from './useDashboardSummary'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

const PARAMS: DashboardSummaryParams = {
  current: { from: '2026-10-08', to: '2026-10-14' },
  comparison: { from: '2026-10-01', to: '2026-10-07' },
}

const METRICS = {
  ordersAmountMinor: 0,
  ordersQuantity: 0,
  salesAmountMinor: 0,
  salesQuantity: 0,
  returnsAmountMinor: 0,
  returnsQuantity: 0,
  advertisingMinor: 0,
  deliveryLogisticsMinor: 0,
  deliveryCount: 0,
  returnLogisticsMinor: 0,
  returnLogisticsCount: 0,
  buyoutOrderedQuantity: 0,
}

function summary(ordersAmountMinor: number): DashboardSummaryResponse {
  const period = {
    from: '2026-10-08',
    to: '2026-10-14',
    dataComplete: true,
    daysWithoutAccruals: 0,
    metrics: { ...METRICS, ordersAmountMinor },
    days: [],
  }

  return {
    currency: 'RUB',
    dataStartsOn: '2026-07-01',
    current: period,
    comparison: period,
    changes: {},
  }
}

describe('ключ кэша главной', () => {
  it('содержит компанию и оба периода', () => {
    // CLAUDE.md §7: при смене компании сводка предыдущей не показывается.
    const key = dashboardSummaryQueryKey(ONE, PARAMS)

    expect(key).toContain(ONE)
    expect(key).not.toEqual(dashboardSummaryQueryKey(TWO, PARAMS))
    expect(key).not.toEqual(
      dashboardSummaryQueryKey(ONE, {
        ...PARAMS,
        comparison: { from: '2025-10-08', to: '2025-10-14' },
      }),
    )
  })

  it('прежние цифры на время загрузки — только той же компании', () => {
    const previous = summary(100)
    const placeholder = previousOfSameCompany(TWO)

    expect(
      placeholder(previous, {
        queryKey: dashboardSummaryQueryKey(ONE, PARAMS),
      }),
    ).toBeUndefined()
    expect(
      placeholder(previous, {
        queryKey: dashboardSummaryQueryKey(TWO, PARAMS),
      }),
    ).toBe(previous)
  })
})

describe('строка запроса главной', () => {
  it('передаёт оба периода явно', () => {
    expect(dashboardSummaryPath(PARAMS)).toBe(
      '/dashboard/summary?from=2026-10-08&to=2026-10-14&compareFrom=2026-10-01&compareTo=2026-10-07',
    )
  })
})

describe('ответ главной', () => {
  it('разбирается по схеме', async () => {
    server.use(
      http.get('/api/companies/{companyId}/dashboard/summary', ({ response }) =>
        response(200).json(summary(12_345)),
      ),
    )

    const body = await apiGet<DashboardSummaryResponse>(
      `http://localhost/api/companies/${ONE}${dashboardSummaryPath(PARAMS)}`,
    )

    expect(body.current.metrics.ordersAmountMinor).toBe(12_345)
  })

  it('неверный период — ApiError 422 с кодом сервера', async () => {
    server.use(
      http.get('/api/companies/{companyId}/dashboard/summary', ({ response }) =>
        response(422).json({
          status: 422,
          code: 'invalid_comparison',
          message: 'compareFrom and compareTo must be a valid period.',
        }),
      ),
    )

    await expect(
      apiGet(
        `http://localhost/api/companies/${ONE}${dashboardSummaryPath(PARAMS)}`,
      ),
    ).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof ApiError &&
        error.status === 422 &&
        error.code === 'invalid_comparison',
    )
  })
})
