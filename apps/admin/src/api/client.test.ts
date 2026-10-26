import { HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'

import { http, server } from '../../tests/msw/server'
import { apiPost } from './client'

describe('защита изменяющих сессионных запросов', () => {
  it('добавляет защитный заголовок к POST без тела', async () => {
    let received: string | null = null
    server.use(
      http.post('/api/admin/auth/login', ({ request }) => {
        received = request.headers.get('X-Conwix-Request')
        return HttpResponse.json(
          { state: 'verification_required' },
          { status: 202 },
        )
      }),
    )

    await apiPost('http://localhost/api/admin/auth/login')

    expect(received).toBe('1')
  })
})
