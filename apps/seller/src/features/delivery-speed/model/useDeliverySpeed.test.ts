import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiGet } from '../../../api/client'
import { http, server } from '../../../../tests/msw/server'
import type { DeliverySpeedParams } from './useDeliverySpeed'
import { deliverySpeedPath, deliverySpeedQueryKey } from './useDeliverySpeed'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

const BASE: DeliverySpeedParams = { days: 30, limit: 20, cursor: null }

describe('ключ кэша отчёта скорости доставки', () => {
  it('содержит компанию, окно и курсор', () => {
    // CLAUDE.md §7: при смене компании отчёт предыдущей не показывается
    // из кэша.
    expect(deliverySpeedQueryKey(ONE, BASE)).toContain(ONE)
    expect(deliverySpeedQueryKey(ONE, BASE)).not.toEqual(
      deliverySpeedQueryKey(TWO, BASE),
    )
    expect(deliverySpeedQueryKey(ONE, BASE)).not.toEqual(
      deliverySpeedQueryKey(ONE, { ...BASE, days: 90 }),
    )
    expect(deliverySpeedQueryKey(ONE, BASE)).not.toEqual(
      deliverySpeedQueryKey(ONE, { ...BASE, cursor: 'next' }),
    )
  })
})

describe('строка запроса отчёта скорости доставки', () => {
  it('передаёт окно и лимит, но не пустой cursor', () => {
    expect(deliverySpeedPath(BASE)).toBe('/delivery-speed?days=30&limit=20')
  })

  it('кодирует cursor как query parameter', () => {
    expect(deliverySpeedPath({ ...BASE, cursor: 'ab+/=' })).toBe(
      '/delivery-speed?days=30&limit=20&cursor=ab%2B%2F%3D',
    )
  })
})

describe('ошибки отчёта скорости доставки', () => {
  it('устаревший курсор — ApiError 422 с кодом сервера', async () => {
    server.use(
      http.get('/api/companies/{companyId}/delivery-speed', ({ response }) =>
        response(422).json({
          status: 422,
          code: 'invalid_cursor',
          message: 'cursor is malformed.',
        }),
      ),
    )

    await expect(
      apiGet(
        `http://localhost/api/companies/${ONE}/delivery-speed?days=30&cursor=x`,
      ),
    ).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof ApiError &&
        error.status === 422 &&
        error.code === 'invalid_cursor',
    )
  })
})
