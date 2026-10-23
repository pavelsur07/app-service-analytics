import { describe, expect, it } from 'vitest'

import {
  formatCoverDays,
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
