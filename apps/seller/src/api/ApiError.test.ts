import { http as rawHttp, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import { http, server } from '../../tests/msw/server'
import { apiGet } from './client'
import { ApiError } from './ApiError'

/**
 * Обязательное покрытие §10: разбор ошибок API.
 *
 * Через мок-сервер и настоящий `apiGet`, а не вызовом `parseApiError`
 * с самодельным `Response`: так проверяется путь целиком — что клиент
 * вообще бросает на неуспешном ответе и что именно долетает
 * до вызывающего.
 *
 * Здесь `http` из msw напрямую, не типизированный `openapi-msw`:
 * контракт описывает успешные ответы и `ValidationErrorResponse`,
 * а половина этих случаев — ответы, которых в схеме нет и быть
 * не должно (HTML от прокси, пустое тело). Типизированный хендлер
 * их бы и не дал написать, а проверять надо именно их.
 */
const ENDPOINT =
  'http://localhost/api/companies/019ff704/ingestion/ozon/sales-facts'

describe('разбор ошибок API', () => {
  it('читает статус, код и сообщение из тела ошибки', async () => {
    // Типизированный хендлер: и путь, и форма ответа сверяются
    // со сгенерированной схемой на этапе компиляции.
    server.use(
      http.get('/api/companies/{companyId}/ingestion/ozon/sales-facts', () =>
        HttpResponse.json(
          {
            status: 422,
            code: 'invalid_limit',
            message: 'limit must be between 1 and 200.',
          },
          { status: 422 },
        ),
      ),
    )

    const error = await apiGet(ENDPOINT).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(422)
    expect((error as ApiError).code).toBe('invalid_limit')
    expect((error as ApiError).message).toBe('limit must be between 1 and 200.')
  })

  it('переживает ответ, которого нет в контракте', async () => {
    // Прокси или nginx отдают HTML — тело не наше, и разбор обязан
    // это пережить, а не упасть на JSON.parse.
    server.use(
      rawHttp.get(ENDPOINT, () =>
        HttpResponse.text('<html>502 Bad Gateway</html>', { status: 502 }),
      ),
    )

    const error = await apiGet(ENDPOINT).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(502)
    expect((error as ApiError).code).toBeNull()
    expect((error as ApiError).message).toBe('HTTP 502')
  })

  it('пустое тело — тоже валидный отказ', async () => {
    server.use(
      rawHttp.get(ENDPOINT, () => new HttpResponse(null, { status: 500 })),
    )

    const error = await apiGet(ENDPOINT).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(500)
    expect((error as ApiError).code).toBeNull()
  })
})
