import { describe, expect, it } from 'vitest'

import { buyoutDailyPath, buyoutDailyQueryKey } from './useBuyoutDaily'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

describe('ключ кэша дневного ряда выкупа', () => {
  it('содержит компанию, окно и SKU', () => {
    const key = buyoutDailyQueryKey(ONE, 'SKU / тест', 30)

    expect(key).toContain(ONE)
    expect(key).not.toEqual(buyoutDailyQueryKey(TWO, 'SKU / тест', 30))
    expect(key).not.toEqual(buyoutDailyQueryKey(ONE, 'another', 30))
    expect(key).not.toEqual(buyoutDailyQueryKey(ONE, 'SKU / тест', 7))
  })
})

describe('путь дневного ряда выкупа', () => {
  it('кодирует SKU как отдельный сегмент пути', () => {
    expect(buyoutDailyPath('SKU / тест', 90)).toBe(
      '/buyout-rate/SKU%20%2F%20%D1%82%D0%B5%D1%81%D1%82/daily?days=90',
    )
  })
})
