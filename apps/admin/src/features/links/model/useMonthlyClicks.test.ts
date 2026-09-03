import { describe, expect, it } from 'vitest'

import { monthlyClicksQueryKey } from './useMonthlyClicks'

describe('ключ кэша переходов', () => {
  it('различает ссылку и месяц', () => {
    const september = monthlyClicksQueryKey('link-one', '2026-09')

    expect(september).toContain('link-one')
    expect(september).toContain('2026-09')
    expect(september).not.toEqual(monthlyClicksQueryKey('link-two', '2026-09'))
    expect(september).not.toEqual(monthlyClicksQueryKey('link-one', '2026-08'))
  })
})
