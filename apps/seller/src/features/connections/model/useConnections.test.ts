import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'
import { ApiError } from '../../../api/ApiError'
import { apiGet } from '../../../api/client'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import { http, server } from '../../../../tests/msw/server'

// Жизненный цикл мок-сервера — общий для всех тестов (tests/msw/setup.ts),
// здесь только хендлеры этого сценария.

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

describe('кэш подключений', () => {
  it('не отдаёт подключения предыдущей компании после переключения', async () => {
    // Ключ обязан содержать companyId (CLAUDE.md §7): иначе бэкенд
    // отработает правильно, а клиент покажет чужие подключения из кэша —
    // на экране, который как раз и должен отвечать «что с моим кабинетом».
    const client = new QueryClient()
    client.setQueryData(companyQueryKey(ONE, 'identity', 'connections'), {
      connections: [{ externalShopId: 'shop-one' }],
    })

    expect(
      client.getQueryData(companyQueryKey(TWO, 'identity', 'connections')),
    ).toBeUndefined()
  })
})

describe('разбор ошибок', () => {
  it('403 приходит как ApiError со статусом', async () => {
    // По нему экран уводит на список компаний: companyId в адресе
    // не означает доступ.
    server.use(
      http.get('/api/companies/{companyId}/connections', () =>
        Response.json(
          { status: 403, code: 'forbidden', message: 'Нет доступа' },
          { status: 403 },
        ),
      ),
    )

    await expect(
      apiGet(`http://localhost/api/companies/${ONE}/connections`),
    ).rejects.toSatisfy(
      (error: unknown) => error instanceof ApiError && error.status === 403,
    )
  })
})
