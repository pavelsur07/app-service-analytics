import { HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import { http, server } from '../../tests/msw/server'
import { apiDelete, apiPost, apiPut } from './client'

describe('защита изменяющих сессионных запросов', () => {
  it('добавляет защитный заголовок к POST без тела', async () => {
    let received: string | null = null
    server.use(
      http.post('/api/auth/login', ({ request }) => {
        received = request.headers.get('X-Conwix-Request')
        return HttpResponse.json({ email: 'user@example.test' })
      }),
    )

    await apiPost('http://localhost/api/auth/login')

    expect(received).toBe('1')
  })
})

describe('остальные изменяющие методы', () => {
  it('защищает PUT с JSON-телом', async () => {
    let received: string | null = null
    server.use(
      http.put(
        '/api/companies/{companyId}/listing-costs/{costId}',
        ({ request }) => {
          received = request.headers.get('X-Conwix-Request')
          return HttpResponse.json(
            { status: 422, code: 'invalid', message: 'Invalid fixture' },
            { status: 422 },
          )
        },
      ),
    )
    await apiPut(
      'http://localhost/api/companies/ours/listing-costs/cost',
      {},
    ).catch(() => undefined)
    expect(received).toBe('1')
  })

  it('защищает DELETE без тела', async () => {
    let received: string | null = null
    server.use(
      http.delete(
        '/api/companies/{companyId}/extension-tokens/{id}',
        ({ request }) => {
          received = request.headers.get('X-Conwix-Request')
          return new HttpResponse(null, { status: 204 })
        },
      ),
    )
    await apiDelete(
      'http://localhost/api/companies/ours/extension-tokens/token',
    )
    expect(received).toBe('1')
  })
})
