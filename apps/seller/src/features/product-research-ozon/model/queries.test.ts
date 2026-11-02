import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiGet, apiPost } from '../../../api/client'
import { http, server } from '../../../../tests/msw/server'
import { errorText } from '../lib/research'
import { researchKey, uiProjectPath } from './queries'
import type { Availability } from './types'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

describe('контракт и изоляция research', () => {
  it('разделяет все ключи компаний и параметры предпросмотра/поиска', () => {
    for (const entity of [
      'availability',
      'projects',
      'project',
      'progress',
      'categories',
      'preview',
    ]) {
      expect(researchKey(ONE, entity)).not.toEqual(researchKey(TWO, entity))
    }
    expect(researchKey(ONE, 'categories', { text: 'хид' })).not.toEqual(
      researchKey(ONE, 'categories', { text: 'лето' }),
    )
    expect(researchKey(ONE, 'preview', { periods: [] })).not.toEqual(
      researchKey(ONE, 'preview', {
        shortcut: { kind: 'last_closed_months', months: 3 },
      }),
    )
    expect(uiProjectPath(ONE, 'a/b')).toContain('/a%2Fb')
  })
  it('данные первой компании не заменяют доступность второй', async () => {
    server.use(
      http.get(
        '/api/companies/{companyId}/product-research-ozon/availability',
        ({ params, response }) =>
          response(200).json({ enabled: params.companyId === ONE }),
      ),
    )
    const query = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    })
    try {
      for (const company of [ONE, TWO]) {
        await query.fetchQuery({
          queryKey: researchKey(company, 'availability'),
          queryFn: () =>
            apiGet<Availability>(
              `http://localhost/api/companies/${company}/product-research-ozon/availability`,
            ),
        })
      }
      expect(query.getQueryData(researchKey(ONE, 'availability'))).toEqual({
        enabled: true,
      })
      expect(query.getQueryData(researchKey(TWO, 'availability'))).toEqual({
        enabled: false,
      })
    } finally {
      query.clear()
    }
  })
  it('503 каталога и 409 предпросмотра остаются ошибками, сообщения безопасны', async () => {
    server.use(
      http.get(
        '/api/companies/{companyId}/product-research-ozon/categories',
        ({ response }) =>
          response(503).json({
            status: 503,
            code: 'catalog_not_ready',
            message: 'private',
          }),
      ),
      http.post(
        '/api/companies/{companyId}/product-research-ozon/preview',
        ({ response }) =>
          response(409).json({
            status: 409,
            code: 'preview_outdated',
            message: 'private',
          }),
      ),
    )
    await expect(
      apiGet(
        `http://localhost/api/companies/${ONE}/product-research-ozon/categories?q=хид`,
      ),
    ).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof ApiError && errorText(error).includes('Справочник'),
    )
    await expect(
      apiPost(
        `http://localhost/api/companies/${ONE}/product-research-ozon/preview`,
        {},
      ),
    ).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof ApiError &&
        errorText(error).includes('календарная дата'),
    )
  })
})
