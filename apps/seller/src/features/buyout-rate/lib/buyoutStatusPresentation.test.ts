import { describe, expect, it } from 'vitest'

import {
  forecastCoverageLabel,
  formatRateBps,
  maturityPresentation,
} from './buyoutStatusPresentation'

describe('формат процента выкупа', () => {
  it('форматирует basis points до двух знаков без лишних нулей', () => {
    expect(formatRateBps(8400)).toBe('84%')
    expect(formatRateBps(8450)).toBe('84,5%')
    expect(formatRateBps(8451)).toBe('84,51%')
    expect(formatRateBps(0)).toBe('0%')
  })

  it('не подменяет неизвестный процент нулём', () => {
    expect(formatRateBps(null)).toBe('Недостаточно данных')
    expect(formatRateBps(undefined)).toBe('Недостаточно данных')
  })
})

describe('статус матурации', () => {
  it('показывает зрелую когорту нейтрально', () => {
    expect(maturityPresentation('mature', 10000)).toEqual({
      label: 'Когорта созрела',
      tone: 'neutral',
    })
  })

  it('называет предварительный расчёт и долю разрешившихся заказов', () => {
    expect(maturityPresentation('preliminary', 6234)).toEqual({
      label: 'Предварительно · 62,34% разрешилось',
      tone: 'warning',
    })
  })
})

describe('доля заказов в прогнозе', () => {
  it('называет долю заказов, по которой построен прогноз', () => {
    expect(forecastCoverageLabel(4500, 500)).toBe('по 95% заказов')
    expect(forecastCoverageLabel(4500, 690)).toBe('по 93,1% заказов')
  })

  it('молчит, когда в прогноз вошли все заказы или прогноза нет', () => {
    expect(forecastCoverageLabel(4500, 0)).toBeNull()
    expect(forecastCoverageLabel(4500, null)).toBeNull()
    expect(forecastCoverageLabel(null, 1200)).toBeNull()
  })
})
