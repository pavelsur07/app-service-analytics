import { describe, expect, it } from 'vitest'
import { isLoss, shareOfRevenue } from './margin'

describe('shareOfRevenue', () => {
  it('считает долю расхода от выручки', () => {
    expect(shareOfRevenue(-6900, 274700)).toBeCloseTo(0.0251, 4)
  })

  it('не делит на ноль, когда выручки за период не было', () => {
    // Обычный случай, а не ошибка: возврат обработан в этом периоде,
    // а продан товар в прошлом.
    expect(shareOfRevenue(-11500, 0)).toBeNull()
  })
})

describe('isLoss', () => {
  it('отрицательный итог — убыток', () => {
    expect(isLoss(-11500)).toBe(true)
    expect(isLoss(0)).toBe(false)
  })
})
