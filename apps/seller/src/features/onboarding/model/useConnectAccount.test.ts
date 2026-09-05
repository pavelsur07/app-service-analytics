import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiPost } from '../../../api/client'
import type { components } from '../../../api/schema'
import { http, server } from '../../../../tests/msw/server'
import { connectionsQueryKey } from '../../../shared/lib/connectionsQueryKey'
import { connectAccount } from './useConnectAccount'
import type { ConnectAccountInput } from './useConnectAccount'

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

const INPUT: ConnectAccountInput = {
  name: 'Мой магазин',
  clientId: 'client-id-value',
  apiKey: 'api-key-value',
}
const RESPONSE: ConnectedAccountResponse = {
  id: 'connection-id',
  name: INPUT.name,
  state: 'active',
}

/**
 * Сама мутация (§10, обязательное покрытие — форма отправляет введённое
 * и изоляция кэша при смене компании). `useConnectAccount` собрать
 * в тесте без DOM-окружения нельзя (vite.config.ts,
 * test.environment: 'node') — поэтому проверяется `connectAccount`,
 * вынесенная функция с инъекцией зависимостей, тем же приёмом, что
 * `confirmEmailAttempt` в useConfirmEmail.test.ts.
 *
 * Обращение к company-scoped пути здесь именно то, каким его строит
 * продакшен-код: `connectAccount` вызывает `createCompanyApiClient`
 * (см. `useConnectAccount`), и это проверено выше через `events`
 * (`post:/connections`) — сам клиент не подставной.
 */
describe('connectAccount', () => {
  it('отправляет введённые поля на /connections и возвращает ответ площадки', async () => {
    const events: string[] = []

    const result = await connectAccount('company-a', INPUT, {
      post: async (path, body) => {
        events.push(`post:${path}`)
        expect(body).toEqual(INPUT)
        return RESPONSE
      },
      invalidateConnections: async () => {
        events.push('invalidate')
      },
    })

    expect(result).toEqual(RESPONSE)
    // Инвалидация — после успешного post, не раньше и не параллельно:
    // до ответа площадки список остаётся тем, что уже показан на экране.
    expect(events).toEqual(['post:/connections', 'invalidate'])
  })

  it('инвалидирует ключ ровно той компании, с которой создан хук', async () => {
    let invalidatedQueryKey: readonly unknown[] | null = null

    await connectAccount('company-a', INPUT, {
      post: async () => RESPONSE,
      invalidateConnections: async (queryKey) => {
        invalidatedQueryKey = queryKey
      },
    })

    expect(invalidatedQueryKey).toEqual(connectionsQueryKey('company-a'))
    expect(invalidatedQueryKey).not.toEqual(connectionsQueryKey('company-b'))
  })

  it('не трогает кэш, когда площадка отклонила ключ', async () => {
    let invalidated = false

    await connectAccount('company-a', INPUT, {
      post: async () => {
        throw new ApiError(422, 'credentials_rejected', 'backend-only detail')
      },
      invalidateConnections: async () => {
        invalidated = true
      },
    }).catch(() => undefined)

    expect(invalidated).toBe(false)
  })
})

const COMPANY_ID = '019ffe00-0000-7000-8000-000000000009'
const ENDPOINT = `http://localhost/api/companies/${COMPANY_ID}/connections`

/**
 * Сетевой контракт мутации (§10, обязательное покрытие — форма
 * отправляет введённое). Ниже проверяется форма запроса на проводе —
 * путь, тело, разбор типизированного ответа и ошибки — а не то, каким
 * путём приложение приходит к данным компании: этот вопрос закрыт
 * тестами `connectAccount` выше, где обращение реально идёт через
 * `createCompanyApiClient`. `apiPost` здесь — тот же приём, что
 * в useConfirmEmail.test.ts и useSignUp.test.ts: `createCompanyApiClient`
 * строит относительный путь, а в Node у него нет базы для `fetch`
 * (предел окружения, tests/msw/server.ts), поэтому провод проверяется
 * `apiPost` по абсолютному адресу того же пути, что строит
 * `useConnectAccount` — не подменой `fetch` и не собранным руками
 * `Response` (запрещено §10 дословно), а настоящим сетевым вызовом
 * через msw.
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
