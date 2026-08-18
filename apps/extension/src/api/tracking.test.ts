import { HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import { http, server } from '../../tests/msw/server'

import {
  ApiError,
  fetchTrackedSkuPage,
  startTracking,
  stopTracking,
} from './client'

const COMPANY = '01a01104-8634-7bee-9c85-8f524802c241'
const TRACKED = '/api/extension/companies/{companyId}/tracked-skus'
const STOP =
  '/api/extension/companies/{companyId}/tracked-skus/{marketplaceSku}/stop'

describe('список отслеживаемых артикулов', () => {
  it('передаёт лимит и курсор и возвращает страницу', async () => {
    const query: Record<string, string | null> = {}
    server.use(
      http.get(TRACKED, ({ request }) => {
        const url = new URL(request.url)
        query.limit = url.searchParams.get('limit')
        query.cursor = url.searchParams.get('cursor')

        return HttpResponse.json({ items: ['111', '222'], nextCursor: '222' })
      }),
    )

    const page = await fetchTrackedSkuPage(
      'conwix_ext_token',
      COMPANY,
      '110',
      200,
    )

    expect(page.items).toEqual(['111', '222'])
    expect(page.nextCursor).toBe('222')
    expect(query.limit).toBe('200')
    expect(query.cursor).toBe('110')
  })
})

describe('включение и остановка отслеживания', () => {
  it('шлёт артикул телом и предъявляет токен', async () => {
    let body: unknown = null
    let authorization: string | null = null
    server.use(
      http.post(TRACKED, async ({ request }) => {
        body = await request.json()
        authorization = request.headers.get('Authorization')

        return HttpResponse.json(null)
      }),
    )

    await startTracking('conwix_ext_token', COMPANY, '123456789')

    expect(body).toEqual({ marketplaceSku: '123456789' })
    expect(authorization).toBe('Bearer conwix_ext_token')
  })

  it('останавливает артикул адресом, без тела', async () => {
    let path: string | null = null
    server.use(
      http.post(STOP, ({ request }) => {
        path = new URL(request.url).pathname

        return HttpResponse.json(null)
      }),
    )

    await stopTracking('conwix_ext_token', COMPANY, '123456789')

    expect(path).toBe(
      `/api/extension/companies/${COMPANY}/tracked-skus/123456789/stop`,
    )
  })

  it('поднимает отказ сервера вместе с его текстом', async () => {
    // Текст объясняет причину («больше 50 артикулов», «нет активного
    // подключения»), и подменять его общим «не удалось» значило бы
    // прятать от продавца то, что ему нужно знать.
    server.use(
      http.post(TRACKED, () =>
        HttpResponse.json(
          {
            status: 422,
            code: 'tracked_sku_limit_reached',
            message: 'Больше 50 артикулов одновременно не отслеживается.',
          },
          { status: 422 },
        ),
      ),
    )

    const error = await startTracking('conwix_ext_token', COMPANY, '1').catch(
      (caught: unknown) => caught,
    )

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).code).toBe('tracked_sku_limit_reached')
    expect((error as ApiError).message).toContain('Больше 50')
  })
})
