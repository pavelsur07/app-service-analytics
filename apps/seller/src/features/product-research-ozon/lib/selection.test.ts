import { describe, expect, it } from 'vitest'

import {
  previousYear,
  previewInput,
  reachesCurrent,
  validPeriods,
} from './selection'
import { startInput, uncertainWrite } from './research'
import { ApiError } from '../../../api/ApiError'

const season = { fromMonth: '2026-01', toMonth: '2026-03', name: 'Весна' }
describe('ввод исследования', () => {
  it('добавление периода отправляет объединение в preview без потери текущего', () => {
    expect(
      previewInput({ periods: [previousYear(season)] }, false, 'Категория', [
        season,
      ]),
    ).toEqual({
      periods: [
        season,
        { fromMonth: '2025-01', toMonth: '2025-03', name: 'Весна' },
      ],
      categoryPath: 'Категория',
      includeCurrentMonth: false,
    })
  })
  it('блокирует незаполненный/будущий диапазон, текущий виден явно', () => {
    expect(validPeriods([season], '2026-04-15')).toBe(true)
    expect(
      validPeriods([{ ...season, toMonth: '2026-05' }], '2026-04-15'),
    ).toBe(false)
    expect(validPeriods([{ ...season, fromMonth: '' }], '2026-04-15')).toBe(
      false,
    )
    expect(
      reachesCurrent([{ ...season, toMonth: '2026-04' }], '2026-04-15'),
    ).toBe(true)
  })
  it('сохраняет intent после потери ответа несмотря на новую версию/дату проекта', () => {
    const body = startInput(null, 1, '2026-04-15')
    expect(startInput(body, 2, '2026-04-16')).toBe(body)
    expect(uncertainWrite(new TypeError('network'))).toBe(true)
    expect(uncertainWrite(new ApiError(500, null, 'private'))).toBe(true)
    expect(
      uncertainWrite(new ApiError(409, 'version_conflict', 'private')),
    ).toBe(false)
  })
})
