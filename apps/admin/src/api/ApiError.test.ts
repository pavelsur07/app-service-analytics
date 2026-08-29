import { describe, expect, it } from 'vitest'
import { http, server } from '../../tests/msw/server'
import { ApiError } from './ApiError'
import { apiGet, apiPost } from './client'

// Обязательное покрытие CLAUDE.md §10: разбор ошибок API.
//
// Ответы описаны через openapi-msw, то есть по сгенерированной схеме:
// тело неверной формы не скомпилировалось бы, и тест не может пройти
// на ответе, которого контракт не допускает.
describe('разбор ошибок API', () => {
  it('достаёт код и сообщение из тела отказа', async () => {
    server.use(
      http.get('/api/admin/auth/me', ({ response }) =>
        response(401).json({
          status: 401,
          code: 'unauthenticated',
          message: 'Full authentication is required to access this resource.',
        }),
      ),
    )

    const error = await apiGet('http://localhost/api/admin/auth/me').catch(
      (caught: unknown) => caught,
    )

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(401)
    expect((error as ApiError).code).toBe('unauthenticated')
    expect((error as ApiError).message).toContain('Full authentication')
  })

  it('переживает ответ без разбираемого тела', async () => {
    // Прокси может вернуть HTML вместо JSON — это валидный случай,
    // а не повод падать самому. Хендлер сырой (не типизированный
    // openapi-msw), потому что такого ответа в схеме нет намеренно.
    server.use(
      http.untyped.get(
        'http://localhost/api/admin/auth/me',
        () =>
          new Response('<html>502 Bad Gateway</html>', {
            status: 502,
            headers: { 'Content-Type': 'text/html' },
          }),
      ),
    )

    const error = await apiGet('http://localhost/api/admin/auth/me').catch(
      (caught: unknown) => caught,
    )

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(502)
    expect((error as ApiError).code).toBeNull()
    expect((error as ApiError).message).toBe('HTTP 502')
  })

  it('разбирает конфликт при заведении администратора', async () => {
    server.use(
      http.post('/api/admin/administrators', ({ response }) =>
        response(409).json({
          status: 409,
          code: 'administrator_email_taken',
          message: 'Администратор с таким адресом уже заведён.',
        }),
      ),
    )

    const error = await apiPost('http://localhost/api/admin/administrators', {
      email: 'taken@conwix.local',
      password: 'long-enough-password',
    }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).status).toBe(409)
    expect((error as ApiError).code).toBe('administrator_email_taken')
  })
})
