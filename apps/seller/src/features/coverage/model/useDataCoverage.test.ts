import { describe, expect, it } from 'vitest'
import { ApiError } from '../../../api/ApiError'
import { apiGet } from '../../../api/client'
import { http, server } from '../../../../tests/msw/server'
import { dataCoverageQueryKey } from './useDataCoverage'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'
const ACCOUNT = '019ffe00-0000-7000-8000-00000000000a'

describe('ключ кэша полноты данных', () => {
  it('содержит companyId и различает компании, кабинеты и месяцы', () => {
    // CLAUDE.md §7: при смене компании отчёт предыдущей не показывается
    // из кэша.
    expect(dataCoverageQueryKey(ONE, ACCOUNT, '2026-09')).toContain(ONE)
    expect(dataCoverageQueryKey(ONE, ACCOUNT, '2026-09')).not.toEqual(
      dataCoverageQueryKey(TWO, ACCOUNT, '2026-09'),
    )
    expect(dataCoverageQueryKey(ONE, ACCOUNT, '2026-09')).not.toEqual(
      dataCoverageQueryKey(ONE, TWO, '2026-09'),
    )
    expect(dataCoverageQueryKey(ONE, ACCOUNT, '2026-09')).not.toEqual(
      dataCoverageQueryKey(ONE, ACCOUNT, '2026-08'),
    )
  })
})

describe('ошибки отчёта', () => {
  it('чужой кабинет — ApiError 404 с кодом сервера', async () => {
    server.use(
      http.get(
        '/api/companies/{companyId}/connections/{marketplaceAccountId}/coverage',
        ({ response }) =>
          response(404).json({
            status: 404,
            code: 'connection_not_found',
            message: 'Подключение не найдено.',
          }),
      ),
    )

    await expect(
      apiGet(
        `http://localhost/api/companies/${ONE}/connections/${ACCOUNT}/coverage?month=2026-09`,
      ),
    ).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof ApiError &&
        error.status === 404 &&
        error.code === 'connection_not_found',
    )
  })
})
