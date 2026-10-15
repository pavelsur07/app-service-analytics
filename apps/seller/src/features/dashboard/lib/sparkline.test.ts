import { describe, expect, it } from 'vitest'

import { sharedScale, sparklinePoints, sparklineTrend } from './sparkline'

describe('спарклайн', () => {
  it('ряд растягивается на ширину, минимум внизу, максимум вверху', () => {
    const scale = sharedScale([0, 50, 100])

    expect(sparklinePoints([0, 50, 100], scale)).toBe(
      '0.00,28.00 50.00,16.00 100.00,4.00',
    )
  })

  it('шкала общая для обоих рядов', () => {
    const scale = sharedScale([10, 20], [0, 40])

    expect(scale).toEqual({ low: 0, high: 40 })
    expect(sparklinePoints([40], scale)).toBe('50.00,4.00')
  })

  it('ровный и пустой ряды не делят на ноль', () => {
    expect(sparklinePoints([5, 5], sharedScale([5, 5]))).toBe(
      '0.00,28.00 100.00,28.00',
    )
    expect(sparklinePoints([], sharedScale([]))).toBe('')
  })

  it('направление ряда для чтения с экрана', () => {
    expect(sparklineTrend([1, 3])).toBe('вырос')
    expect(sparklineTrend([3, 1])).toBe('снизился')
    expect(sparklineTrend([])).toBe('не изменился')
  })
})
