import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError, fetchMe, isUnauthorized } from './client'

function respondWith(status: number, body: unknown): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(() =>
      Promise.resolve(
        new Response('string' === typeof body ? body : JSON.stringify(body), {
          status,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    ),
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('разбор ошибок API', () => {
  it('читает код и сообщение из тела ошибки', async () => {
    respondWith(401, {
      status: 401,
      code: 'invalid_extension_token',
      message: 'Extension token is missing or invalid.',
    })

    const error = await fetchMe('conwix_ext_stale').catch(
      (caught: unknown) => caught,
    )

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(401)
    expect((error as ApiError).code).toBe('invalid_extension_token')
  })

  it('переживает ответ без нашего тела ошибки', async () => {
    // Прокси или сеть могут вернуть HTML вместо JSON. Расширение обязано
    // показать «недоступно», а не упасть на разборе.
    respondWith(502, '<html>Bad Gateway</html>')

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
    respondWith(200, {
      email: 'owner@example.com',
      company: { id: '019ff5ce-e740-7065-b0eb-e8f9acda89ef', name: 'Acme LLC' },
    })

    const me = await fetchMe('conwix_ext_live')

    expect(me.email).toBe('owner@example.com')
    expect(me.company.name).toBe('Acme LLC')
  })

  it('токен уходит в заголовке Authorization, а не в куке', async () => {
    respondWith(200, {
      email: 'owner@example.com',
      company: { id: '019ff5ce-e740-7065-b0eb-e8f9acda89ef', name: 'Acme LLC' },
    })

    await fetchMe('conwix_ext_live')

    const [, init] = vi.mocked(fetch).mock.calls[0] ?? []
    expect(
      (init?.headers as Record<string, string> | undefined)?.Authorization,
    ).toBe('Bearer conwix_ext_live')
    // credentials не выставляем: сессионная кука расширению не положена
    // (ADR-010), и просить её у браузера незачем.
    expect(init?.credentials).toBeUndefined()
  })
})
