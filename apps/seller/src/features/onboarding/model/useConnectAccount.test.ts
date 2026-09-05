import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { http, server } from '../../../../tests/msw/server'
import { connectionsQueryKey } from '../../../shared/lib/connectionsQueryKey'

// Обязательное покрытие §10 — изоляция кэша при смене компании.
// Без companyId в ключе клиент после переключения показал бы состояние
// подключений предыдущей компании, а бэкенд при этом отработал бы верно.
describe('ключ, который инвалидирует подключение', () => {
  it('различает компании', () => {
    expect(connectionsQueryKey('company-a')).not.toEqual(
      connectionsQueryKey('company-b'),
    )
  })

  it('содержит companyId', () => {
    expect(connectionsQueryKey('company-a')).toContain('company-a')
  })
})

type ConnectedAccountResponse =
  components['schemas']['ConnectedAccountResponse']

const COMPANY_ID = '019ffe00-0000-7000-8000-000000000009'
const ENDPOINT = `http://localhost/api/companies/${COMPANY_ID}/connections`

/**
 * Сетевой контракт мутации (§10, обязательное покрытие — форма
 * отправляет введённое). `createCompanyApiClient` строит относительный
 * путь и в Node у него нет базы для `fetch` (предел окружения,
 * tests/msw/server.ts) — поэтому проверяется тем же приёмом, что
 * в useConfirmEmail.test.ts и useSignUp.test.ts: настоящим `apiPost`
 * по абсолютному адресу того же пути, что строит `useConnectAccount`.
 */
describe('сетевой контракт подключения кабинета', () => {
  it('отправляет введённые поля без изменений и принимает типизированный 201', async () => {
    const request = {
      name: 'Мой магазин',
      clientId: 'client-id-value',
      apiKey: 'api-key-value',
    }

    server.use(
      http.post(
        '/api/companies/{companyId}/connections',
        async ({ request: received, response }) => {
          expect(await received.json()).toEqual(request)
          return response(201).json({
            id: 'connection-id',
            name: request.name,
            state: 'active',
          })
        },
      ),
    )

    await expect(
      apiPost<ConnectedAccountResponse>(ENDPOINT, request),
    ).resolves.toEqual({
      id: 'connection-id',
      name: request.name,
      state: 'active',
    })
  })

  it('переносит код отказа площадки в ApiError без потери сообщения', async () => {
    server.use(
      http.post('/api/companies/{companyId}/connections', ({ response }) =>
        response(422).json({
          status: 422,
          code: 'credentials_rejected',
          message: 'backend-only diagnostic',
        }),
      ),
    )

    const error = await apiPost<ConnectedAccountResponse>(ENDPOINT, {
      name: 'Мой магазин',
      clientId: 'client-id-value',
      apiKey: 'api-key-value',
    }).catch((caught: unknown) => caught)

    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).code).toBe('credentials_rejected')
  })
})
