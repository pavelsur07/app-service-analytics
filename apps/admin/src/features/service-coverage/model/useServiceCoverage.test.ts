import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiGet } from '../../../api/client'
import { http, server } from '../../../../tests/msw/server'
import {
  serviceCoveragePath,
  serviceCoverageQueryKey,
  type ServiceCoverageResponse,
} from './useServiceCoverage'

describe('запрос покрытия GO', () => {
  it('окно в ключе кэша и в адресе', () => {
    expect(serviceCoverageQueryKey(31)).not.toEqual(serviceCoverageQueryKey(93))
    expect(serviceCoveragePath(93)).toBe(
      '/api/admin/services/ozon-fetcher/coverage?days=93',
    )
  })

  it('недоступный сервис приходит состоянием, а не ошибкой запроса', async () => {
    server.use(
      http.get('/api/admin/services/ozon-fetcher/coverage', ({ response }) =>
        response(200).json({
          from: '2026-09-25',
          to: '2026-10-25',
          checkedAt: '2026-10-25T09:00:00+00:00',
          serviceState: 'unavailable',
          attemptedAt: '2026-10-25T08:58:00+00:00',
          serviceGeneratedAt: null,
          cabinetsSeller: null,
          cabinetsPerformance: null,
          phpCompared: true,
          rows: [],
        }),
      ),
    )

    const data = await apiGet<ServiceCoverageResponse>(
      `http://localhost${serviceCoveragePath(31)}`,
    )

    expect(data.serviceState).toBe('unavailable')
  })

  it('неверное окно — ApiError 422 с кодом сервера', async () => {
    server.use(
      http.get('/api/admin/services/ozon-fetcher/coverage', ({ response }) =>
        response(422).json({
          status: 422,
          code: 'days_invalid',
          message: 'Окно — 31 или 93 дня.',
        }),
      ),
    )

    await expect(
      apiGet(
        'http://localhost/api/admin/services/ozon-fetcher/coverage?days=7',
      ),
    ).rejects.toMatchObject({ status: 422, code: 'days_invalid' })
    await expect(
      apiGet(
        'http://localhost/api/admin/services/ozon-fetcher/coverage?days=7',
      ),
    ).rejects.toBeInstanceOf(ApiError)
  })
})
