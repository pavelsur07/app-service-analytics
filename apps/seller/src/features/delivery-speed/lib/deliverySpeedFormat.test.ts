import { describe, expect, it } from 'vitest'

import {
  formatBucket,
  formatDuration,
  formatLostTime,
  NO_DATA,
} from './deliverySpeedFormat'

describe('форматирование скорости доставки', () => {
  it('меньше суток — в часах, иначе в днях с одним знаком', () => {
    expect(formatDuration(64_800)).toBe('18 ч')
    expect(formatDuration(193_950)).toBe('2,2 дн')
    expect(formatDuration(369_000)).toBe('4,3 дн')
    expect(formatDuration(null)).toBe(NO_DATA)
  })

  it('потерянное время: меньше суток — в часах, иначе в днях', () => {
    expect(formatLostTime(5)).toBe('5 ч')
    // Бэкенд округляет вверх: положительная потеря — хотя бы 1 ч.
    expect(formatLostTime(1)).toBe('1 ч')
    expect(formatLostTime(0)).toBe('0 ч')
    expect(formatLostTime(486)).toBe('20,3 дн')
    expect(formatLostTime(null)).toBe(NO_DATA)
  })

  it('корзины — с включающей верхней границей и открытым хвостом', () => {
    expect(formatBucket({ minDays: 0, maxDays: 3 })).toBe('0–2 дн')
    expect(formatBucket({ minDays: 8, maxDays: null })).toBe('8+ дн')
  })
})
