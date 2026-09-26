import { describe, expect, it } from 'vitest'

import { formatBucket, formatDuration, NO_DATA } from './deliverySpeedFormat'

describe('форматирование скорости доставки', () => {
  it('меньше суток — в часах, иначе в днях с одним знаком', () => {
    expect(formatDuration(64_800)).toBe('18 ч')
    expect(formatDuration(193_950)).toBe('2,2 дн')
    expect(formatDuration(369_000)).toBe('4,3 дн')
    expect(formatDuration(null)).toBe(NO_DATA)
  })

  it('корзины — с включающей верхней границей и открытым хвостом', () => {
    expect(formatBucket({ minDays: 0, maxDays: 3 })).toBe('0–2 дн')
    expect(formatBucket({ minDays: 8, maxDays: null })).toBe('8+ дн')
  })
})
