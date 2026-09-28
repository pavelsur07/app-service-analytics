import { describe, expect, it } from 'vitest'
import { parseTab, searchWithTab } from './tab'

describe('вкладка экрана', () => {
  it('читает сверку из адреса, остальное — юнит-экономика', () => {
    expect(parseTab('reconciliation')).toBe('reconciliation')
    expect(parseTab(null)).toBe('units')
    expect(parseTab('что-то')).toBe('units')
  })

  it('сохраняет прочие параметры и убирает свой у первой вкладки', () => {
    const search = new URLSearchParams('days=7&sort=margin')

    expect(searchWithTab(search, 'reconciliation').toString()).toBe(
      'days=7&sort=margin&view=reconciliation',
    )
    expect(
      searchWithTab(
        new URLSearchParams('days=7&view=reconciliation'),
        'units',
      ).toString(),
    ).toBe('days=7')
  })
})
