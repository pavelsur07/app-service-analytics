import { describe, expect, it } from 'vitest'
import { isLoss, marginSign, marginTone, shareOfRevenue } from './margin'

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

describe('marginTone', () => {
  it('различает прибыльную строку, убыточную и шум', () => {
    expect(marginTone(50_000, 274_700)).toBe('positive')
    expect(marginTone(-50_000, 274_700)).toBe('negative')
    // Полпроцента — не «заработали» и не «потеряли»: красить это
    // в зелёный значит обещать прибыль там, где её нет.
    expect(marginTone(1_000, 274_700)).toBe('neutral')
  })

  it('держит порог ровно на одном проценте', () => {
    expect(marginTone(1_000, 100_000)).toBe('neutral')
    expect(marginTone(1_001, 100_000)).toBe('positive')
    expect(marginTone(-1_000, 100_000)).toBe('neutral')
    expect(marginTone(-1_001, 100_000)).toBe('negative')
  })

  it('без выручки решает знак самой маржи, а не доля', () => {
    // Расход без продаж — возврат обработан сейчас, а продан товар
    // был раньше. Это худшая строка на экране, а не серая.
    expect(marginTone(-11_500, 0)).toBe('negative')
    expect(marginTone(0, 0)).toBe('neutral')
  })
})

describe('marginSign', () => {
  it('даёт знак, читаемый без цвета', () => {
    // Дальтонизм и чёрно-белая печать отчёта для бухгалтера: цвет
    // усиливает статус, но не несёт его.
    expect(marginSign('positive')).toBe('+')
    expect(marginSign('negative')).toBe('−')
    expect(marginSign('neutral')).toBe('=')
  })
})
