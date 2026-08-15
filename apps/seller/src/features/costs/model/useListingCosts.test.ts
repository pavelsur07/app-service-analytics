import { describe, expect, it } from 'vitest'
import { listingCostsQueryKey } from './useListingCosts'

const ONE = '019ffe00-0000-7000-8000-000000000001'
const TWO = '019ffe00-0000-7000-8000-000000000002'

describe('ключ кэша себестоимости', () => {
  it('содержит companyId и различает компании', () => {
    // CLAUDE.md §7. Здесь цена ошибки выше обычного: себестоимость —
    // коммерческая тайна, и чужие закупочные цены на экране это не
    // «устаревшие данные», а утечка.
    expect(listingCostsQueryKey(ONE, null)).toContain(ONE)
    expect(listingCostsQueryKey(ONE, null)).not.toEqual(
      listingCostsQueryKey(TWO, null),
    )
  })

  it('различает страницы', () => {
    expect(listingCostsQueryKey(ONE, null)).not.toEqual(
      listingCostsQueryKey(ONE, '100000:111'),
    )
  })
})
