import { describe, expect, it } from 'vitest'

import { formatLostTime } from './formatLostTime'

describe('форматирование потерянного времени', () => {
  it('потерянное время: меньше суток — в часах, иначе в днях', () => {
    expect(formatLostTime(5)).toBe('5 ч')
    // Бэкенд округляет вверх: положительная потеря — хотя бы 1 ч.
    expect(formatLostTime(1)).toBe('1 ч')
    expect(formatLostTime(0)).toBe('0 ч')
    expect(formatLostTime(486)).toBe('20,3 дн')
    expect(formatLostTime(null)).toBe('—')
  })
})
