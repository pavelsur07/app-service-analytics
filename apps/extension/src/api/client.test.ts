import { http as rawHttp, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import { http, server } from '../../tests/msw/server'
import { ApiError, API_BASE_URL, fetchMe, isUnauthorized } from './client'

const ME = '/api/extension/me'

describe('разбор ошибок API', () => {
  it('читает код и сообщение из тела ошибки', async () => {
    // Типизированный хендлер: путь и форма ответа сверяются
    // со сгенерированной схемой на этапе компиляции.
    server.use(
      http.get(ME, () =>
        HttpResponse.json(
          {
            status: 401,
            code: 'invalid_extension_token',
            message: 'Extension token is missing or invalid.',
          },
          { status: 401 },
        ),
      ),
    )

    const error = await fetchMe('conwix_ext_stale').catch(
      (caught: unknown) => caught,
    )

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(401)
    expect((error as ApiError).code).toBe('invalid_extension_token')
  })

  it('переживает ответ, которого нет в контракте', async () => {
    // Сырой хендлер msw, не типизированный: HTML от прокси схемой
    // не предусмотрен и предусмотрен быть не может, а проверять
    // поведение на нём надо именно поэтому.
    server.use(
      rawHttp.get(`${API_BASE_URL}${ME}`, () =>
        HttpResponse.text('<html>Bad Gateway</html>', { status: 502 }),
      ),
    )

    const error = await fetchMe('conwix_ext_any').catch(
      (caught: unknown) => caught,
    )

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(502)
    expect((error as ApiError).code).toBeNull()
  })

  it('401 отличается от прочих отказов — только он гасит подключение', () => {
    // Истёк, отозван, исключили из компании — снаружи неотличимы (ADR-010)
    // и все дают 401. Недоступность сервиса токен не отменяет.
    expect(
      isUnauthorized(new ApiError(401, 'invalid_extension_token', 'nope')),
    ).toBe(true)
    expect(isUnauthorized(new ApiError(502, null, 'HTTP 502'))).toBe(false)
    expect(isUnauthorized(new TypeError('Failed to fetch'))).toBe(false)
  })

  it('успешный ответ отдаёт компанию токена', async () => {
    server.use(
      http.get(ME, () =>
        HttpResponse.json({
          email: 'owner@example.com',
          company: {
            id: '019ff5ce-e740-7065-b0eb-e8f9acda89ef',
            name: 'Acme LLC',
          },
        }),
      ),
    )

    const me = await fetchMe('conwix_ext_live')

    expect(me.email).toBe('owner@example.com')
    expect(me.company.name).toBe('Acme LLC')
  })

  it('токен уходит в заголовке Authorization, а не в куке', async () => {
    let authorization: string | null = null
    let credentials: RequestCredentials | undefined

    server.use(
      http.get(ME, ({ request }) => {
        authorization = request.headers.get('Authorization')
        credentials = request.credentials

        return HttpResponse.json({
          email: 'owner@example.com',
          company: {
            id: '019ff5ce-e740-7065-b0eb-e8f9acda89ef',
            name: 'Acme LLC',
          },
        })
      }),
    )

    await fetchMe('conwix_ext_live')

    expect(authorization).toBe('Bearer conwix_ext_live')
    // Сессионная кука расширению не положена (ADR-010): запрос не должен
    // просить её у браузера.
    expect(credentials).not.toBe('include')
  })
})
