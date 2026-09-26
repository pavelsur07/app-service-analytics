import { describe, expect, it } from 'vitest'

import {
  formatPerUnit,
  formatShare,
  formatSources,
  NO_DATA,
} from './localizationFormat'

describe('форматирование отчёта локализации', () => {
  it('долю без данных показывает прочерком, а не нулём', () => {
    expect(formatShare(3704)).toBe('37,04%')
    expect(formatShare(0)).toBe('0%')
    expect(formatShare(null)).toBe(NO_DATA)
    expect(formatShare(undefined)).toBe(NO_DATA)
  })

  it('логистику на штуку — с валютой, без данных — прочерком', () => {
    expect(formatPerUnit(10150, 'RUB')).toMatch(/^101,50\s₽\/шт$/)
    expect(formatPerUnit(null, 'RUB')).toBe(NO_DATA)
    expect(formatPerUnit(101, null)).toBe(NO_DATA)
  })

  it('источники — долями, а без доли — штуками', () => {
    expect(
      formatSources([
        { cluster: 'Москва', quantity: 15, shareBps: 6000 },
        { cluster: 'Омск', quantity: 10, shareBps: 4000 },
      ]),
    ).toBe('Москва 60%, Омск 40%')
    expect(
      formatSources([{ cluster: 'Москва', quantity: 2, shareBps: null }]),
    ).toBe('Москва 2 шт')
    expect(formatSources([])).toBe(NO_DATA)
  })
})
