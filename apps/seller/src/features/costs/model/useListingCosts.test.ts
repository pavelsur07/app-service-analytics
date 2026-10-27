import { QueryClient } from '@tanstack/react-query'
import { companyQueryKey } from '../../../shared/lib/companyQueryKey'
import { describe, expect, it } from 'vitest'
import {
  listingCostsQueryKey,
  invalidateListingCostQueries,
} from './useListingCosts'

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

it('изменение цены обновляет все периоды прибыли только своей компании', async () => {
  const client = new QueryClient()
  const keys = [
    companyQueryKey(ONE, 'ingestion', 'dashboard', { from: '2026-08-01' }),
    companyQueryKey(ONE, 'ingestion', 'dashboard', { from: '2026-09-01' }),
    listingCostsQueryKey(ONE, null),
    companyQueryKey(TWO, 'ingestion', 'dashboard', { from: '2026-08-01' }),
    listingCostsQueryKey(TWO, null),
    ['unrelated'],
  ]
  keys.forEach((key) => client.setQueryData(key, { value: 1 }))
  expect(keys.map((key) => client.getQueryState(key)?.isInvalidated)).toEqual([
    false,
    false,
    false,
    false,
    false,
    false,
  ])
  await invalidateListingCostQueries(client, ONE)
  expect(keys.map((key) => client.getQueryState(key)?.isInvalidated)).toEqual([
    true,
    true,
    true,
    false,
    false,
    false,
  ])
  client.clear()
})
