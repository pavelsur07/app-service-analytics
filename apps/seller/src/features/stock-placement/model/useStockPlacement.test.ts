import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiGet } from '../../../api/client'
import { http, server } from '../../../../tests/msw/server'
import type { StockPlacementParams } from './useStockPlacement'
import { stockPlacementPath, stockPlacementQueryKey } from './useStockPlacement'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

const BASE: StockPlacementParams = {
  targetDays: 28,
  leadDays: 7,
  status: null,
  limit: 50,
  cursor: null,
}

describe('ключ кэша отчёта остатков', () => {
  it('содержит компанию и все параметры расчёта', () => {
    // CLAUDE.md §7: при смене компании отчёт предыдущей не показывается
    // из кэша.
    expect(stockPlacementQueryKey(ONE, BASE)).toContain(ONE)
    expect(stockPlacementQueryKey(ONE, BASE)).not.toEqual(
      stockPlacementQueryKey(TWO, BASE),
    )
    for (const changed of [
      { ...BASE, targetDays: 42 },
      { ...BASE, leadDays: 14 },
      { ...BASE, status: 'deficit' as const },
      { ...BASE, cursor: 'next' },
    ]) {
      expect(stockPlacementQueryKey(ONE, BASE)).not.toEqual(
        stockPlacementQueryKey(ONE, changed),
      )
    }
  })
})

describe('строка запроса отчёта остатков', () => {
  it('без фильтра и курсора — только параметры расчёта и лимит', () => {
    expect(stockPlacementPath(BASE)).toBe(
      '/stock-placement?target_days=28&lead_days=7&limit=50',
    )
  })

  it('фильтр статуса и закодированный курсор', () => {
    expect(
      stockPlacementPath({ ...BASE, status: 'surplus', cursor: 'ab+/=' }),
    ).toBe(
      '/stock-placement?target_days=28&lead_days=7&limit=50&status=surplus&cursor=ab%2B%2F%3D',
    )
  })
})

describe('ошибки отчёта остатков', () => {
  it('устаревший курсор — ApiError 422 с кодом сервера', async () => {
    server.use(
      http.get('/api/companies/{companyId}/stock-placement', ({ response }) =>
        response(422).json({
          status: 422,
          code: 'invalid_cursor',
          message: 'cursor is malformed.',
        }),
      ),
    )

    await expect(
      apiGet(`http://localhost/api/companies/${ONE}/stock-placement?cursor=x`),
    ).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof ApiError &&
        error.status === 422 &&
        error.code === 'invalid_cursor',
    )
  })
})
