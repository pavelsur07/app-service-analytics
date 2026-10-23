import { describe, expect, it } from 'vitest'

import { formatDemand } from './formatDemand'

describe('форматирование спроса', () => {
  it('из тысячных долей штуки в день', () => {
    expect(formatDemand(1500)).toBe('1,5 шт/дн')
    // Одна продажа за 28 дней — 36 тысячных: не ноль.
    expect(formatDemand(36)).toBe('<0,1 шт/дн')
    expect(formatDemand(50)).toBe('0,1 шт/дн')
    expect(formatDemand(0)).toBe('0 шт/дн')
  })
})
