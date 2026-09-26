import { describe, expect, it } from 'vitest'

import { ApiError } from '../../../api/ApiError'
import { apiGet } from '../../../api/client'
import { http, server } from '../../../../tests/msw/server'
import type { LocalizationParams } from './useLocalizationReport'
import { localizationPath, localizationQueryKey } from './useLocalizationReport'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

const BASE: LocalizationParams = { days: 30, limit: 20, cursor: null }

describe('ключ кэша отчёта локализации', () => {
  it('содержит компанию, окно и курсор', () => {
    // CLAUDE.md §7: при смене компании отчёт предыдущей не показывается
    // из кэша.
    expect(localizationQueryKey(ONE, BASE)).toContain(ONE)
    expect(localizationQueryKey(ONE, BASE)).not.toEqual(
      localizationQueryKey(TWO, BASE),
    )
    expect(localizationQueryKey(ONE, BASE)).not.toEqual(
      localizationQueryKey(ONE, { ...BASE, days: 90 }),
    )
    expect(localizationQueryKey(ONE, BASE)).not.toEqual(
      localizationQueryKey(ONE, { ...BASE, cursor: 'next' }),
    )
  })
})

describe('строка запроса отчёта локализации', () => {
  it('передаёт окно и лимит, но не пустой cursor', () => {
    expect(localizationPath(BASE)).toBe('/localization?days=30&limit=20')
  })

  it('кодирует cursor как query parameter', () => {
    expect(localizationPath({ ...BASE, cursor: 'ab+/=' })).toBe(
      '/localization?days=30&limit=20&cursor=ab%2B%2F%3D',
    )
  })
})

describe('ошибки отчёта локализации', () => {
  it('устаревший курсор — ApiError 422 с кодом сервера', async () => {
    server.use(
      http.get('/api/companies/{companyId}/localization', ({ response }) =>
        response(422).json({
          status: 422,
          code: 'invalid_cursor',
          message: 'cursor is malformed.',
        }),
      ),
    )

    await expect(
      apiGet(
        `http://localhost/api/companies/${ONE}/localization?days=30&cursor=x`,
      ),
    ).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof ApiError &&
        error.status === 422 &&
        error.code === 'invalid_cursor',
    )
  })
})
