import { describe, expect, it } from 'vitest'

import type { BuyoutRatesParams } from './useBuyoutRates'
import { buyoutRatesPath, buyoutRatesQueryKey } from './useBuyoutRates'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

const BASE: BuyoutRatesParams = { days: 30, limit: 50, cursor: null }

describe('ключ кэша процента выкупа', () => {
  it('содержит компанию, окно и курсор', () => {
    expect(buyoutRatesQueryKey(ONE, BASE)).toContain(ONE)
    expect(buyoutRatesQueryKey(ONE, BASE)).not.toEqual(
      buyoutRatesQueryKey(TWO, BASE),
    )
    expect(buyoutRatesQueryKey(ONE, BASE)).not.toEqual(
      buyoutRatesQueryKey(ONE, { ...BASE, days: 7 }),
    )
    expect(buyoutRatesQueryKey(ONE, BASE)).not.toEqual(
      buyoutRatesQueryKey(ONE, { ...BASE, cursor: 'next' }),
    )
  })
})

describe('строка запроса процента выкупа', () => {
  it('передаёт окно и лимит, но не пустой cursor', () => {
    expect(buyoutRatesPath(BASE)).toBe('/buyout-rate?days=30&limit=50')
  })

  it('кодирует cursor как query parameter', () => {
    expect(buyoutRatesPath({ ...BASE, cursor: 'SKU+/= cursor' })).toBe(
      '/buyout-rate?days=30&limit=50&cursor=SKU%2B%2F%3D+cursor',
    )
  })
})
