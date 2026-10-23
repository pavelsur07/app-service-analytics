import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiGet } from '../../../api/client'
import { http, server } from '../../../../tests/msw/server'
import type { DemandMatrixParams } from './useDemandMatrix'
import { demandMatrixPath, demandMatrixQueryKey } from './useDemandMatrix'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

const BASE: DemandMatrixParams = { search: '', limit: 50, cursor: null }

describe('ключ кэша матрицы спроса', () => {
  it('содержит компанию, поиск и курсор', () => {
    // CLAUDE.md §7: при смене компании матрица предыдущей не показывается
    // из кэша.
    expect(demandMatrixQueryKey(ONE, BASE)).toContain(ONE)
    expect(demandMatrixQueryKey(ONE, BASE)).not.toEqual(
      demandMatrixQueryKey(TWO, BASE),
    )
    for (const changed of [
      { ...BASE, search: 'abc' },
      { ...BASE, cursor: 'next' },
    ]) {
      expect(demandMatrixQueryKey(ONE, BASE)).not.toEqual(
        demandMatrixQueryKey(ONE, changed),
      )
    }
  })
})

describe('строка запроса матрицы спроса', () => {
  it('без поиска и курсора — только лимит', () => {
    expect(demandMatrixPath(BASE)).toBe('/demand-matrix?limit=50')
  })

  it('поиск и курсор закодированы', () => {
    expect(
      demandMatrixPath({ ...BASE, search: 'синий & белый', cursor: 'ab+/=' }),
    ).toBe(
      '/demand-matrix?limit=50&search=%D1%81%D0%B8%D0%BD%D0%B8%D0%B9+%26+%D0%B1%D0%B5%D0%BB%D1%8B%D0%B9&cursor=ab%2B%2F%3D',
    )
  })
})

describe('ошибки матрицы спроса', () => {
  it('устаревший курсор — ApiError 422 с кодом сервера', async () => {
    server.use(
      http.get('/api/companies/{companyId}/demand-matrix', ({ response }) =>
        response(422).json({
          status: 422,
          code: 'invalid_cursor',
          message: 'cursor is malformed.',
        }),
      ),
    )

    await expect(
      apiGet(`http://localhost/api/companies/${ONE}/demand-matrix?cursor=x`),
    ).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof ApiError &&
        error.status === 422 &&
        error.code === 'invalid_cursor',
    )
  })
})
