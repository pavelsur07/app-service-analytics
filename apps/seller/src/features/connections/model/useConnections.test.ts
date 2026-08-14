import { describe, expect, it } from 'vitest'
import { ApiError } from '../../../api/ApiError'
import { apiGet } from '../../../api/client'
import { http, server } from '../../../../tests/msw/server'
import { connectionsQueryKey } from './useConnections'

// Жизненный цикл мок-сервера — общий для всех тестов (tests/msw/setup.ts),
// здесь только хендлеры этого сценария.

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

describe('ключ кэша подключений', () => {
  it('содержит companyId и различает компании', () => {
    // Проверяется ключ самого хука, а не собранный в тесте: иначе тест
    // прошёл бы и у хука, потерявшего companyId, — а именно это даёт
    // чужие подключения из кэша после переключения компании (§7).
    expect(connectionsQueryKey(ONE)).toContain(ONE)
    expect(connectionsQueryKey(ONE)).not.toEqual(connectionsQueryKey(TWO))
  })
})

describe('разбор ошибок', () => {
  it('403 приходит как ApiError со статусом', async () => {
    // Ответ описан в контракте и возвращается типизированным хендлером
    // (§10): собранный руками Response прошёл бы и на теле, которого
    // схема не допускает.
    server.use(
      http.get('/api/companies/{companyId}/connections', ({ response }) =>
        response(403).json({
          status: 403,
          code: 'forbidden',
          message: 'Нет доступа',
        }),
      ),
    )

    // apiGet абсолютным адресом, а не createCompanyApiClient: клиент
    // строит относительный путь, а в Node у него нет базы — предел
    // окружения, описанный в tests/msw/server.ts. Подстановка companyId
    // в путь покрыта e2e и §1 на бэкенде.
    await expect(
      apiGet(`http://localhost/api/companies/${ONE}/connections`),
    ).rejects.toSatisfy(
      (error: unknown) => error instanceof ApiError && error.status === 403,
    )
  })
})
