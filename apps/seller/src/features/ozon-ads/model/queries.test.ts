import { QueryClient } from '@tanstack/react-query'
import { describe, expect, it } from 'vitest'

import { adsKey, listParams } from './queries'
import type { PolicyList } from './types'
import { samplePolicy } from '../lib/policy.fixture'

const ONE = '019a0000-0000-7000-8000-000000000011'
const TWO = '019a0000-0000-7000-8000-000000000012'
describe('Ads: ключи кэша и фильтры', () => {
  it('не подставляет политику первой компании во вторую и разделяет страницы/фильтры', () => {
    const query = new QueryClient()
    try {
      query.setQueryData<PolicyList>(adsKey(ONE, 'policies'), {
        items: [samplePolicy()],
        nextCursor: null,
      })
      query.setQueryData<PolicyList>(adsKey(TWO, 'policies'), {
        items: [],
        nextCursor: null,
      })
      expect(
        query.getQueryData<PolicyList>(adsKey(ONE, 'policies'))?.items,
      ).toHaveLength(1)
      expect(
        query.getQueryData<PolicyList>(adsKey(TWO, 'policies'))?.items,
      ).toHaveLength(0)
      expect(adsKey(ONE, 'events')).not.toEqual(adsKey(TWO, 'events'))
      expect(adsKey(ONE, 'policies', { params: 'a' })).not.toEqual(
        adsKey(ONE, 'policies', { params: 'b' }),
      )
      expect(listParams('', null)).toBe('limit=20')
      expect(listParams(ONE, TWO, 'false')).toContain('enabled=false')
      expect(listParams(ONE, TWO)).toContain(`cursor=${TWO}`)
    } finally {
      query.clear()
    }
  })
})
