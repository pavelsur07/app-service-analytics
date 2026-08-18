import { describe, expect, it } from 'vitest'

import { priceOverviewQueryKey } from './usePriceOverview'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

describe('ключ кэша экрана цен', () => {
  it('содержит companyId и различает компании', () => {
    // CLAUDE.md §7. Без companyId в ключе клиент отдал бы данные
    // предыдущей компании из кэша при переключении — бэкенд при этом
    // отработал бы правильно, и заметить подмену было бы нечем.
    // Здесь на экране цены кабинета, то есть коммерческая тайна.
    expect(priceOverviewQueryKey(ONE)).toContain(ONE)
    expect(priceOverviewQueryKey(ONE)).not.toEqual(priceOverviewQueryKey(TWO))
  })
})
