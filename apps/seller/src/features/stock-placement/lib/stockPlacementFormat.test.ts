import { describe, expect, it } from 'vitest'

import {
  formatCoverDays,
  formatDemand,
  formatOzonRate,
  formatUnits,
  NO_DATA,
} from './stockPlacementFormat'

describe('форматирование остатков', () => {
  it('неизвестный остаток — прочерк, а не ноль', () => {
    expect(formatUnits(null)).toBe(NO_DATA)
    expect(formatUnits(0)).toBe('0')
    expect(formatUnits(1250)).toBe('1 250')
  })

  it('спрос из тысячных долей штуки в день', () => {
    expect(formatDemand(1500)).toBe('1,5 шт/дн')
    // Одна продажа за 28 дней — 36 тысячных: не ноль.
    expect(formatDemand(36)).toBe('<0,1 шт/дн')
    expect(formatDemand(50)).toBe('0,1 шт/дн')
    expect(formatDemand(0)).toBe('0 шт/дн')
  })

  it('покрытие в днях; без спроса — прочерк', () => {
    expect(formatCoverDays(12)).toBe('12 дн')
    expect(formatCoverDays(null)).toBe(NO_DATA)
  })

  it('скорость Ozon строкой numeric — с одним знаком', () => {
    expect(formatOzonRate('2.2500')).toBe('2,3')
    expect(formatOzonRate('0.0000')).toBe('0')
    expect(formatOzonRate(null)).toBe(NO_DATA)
  })
})
