import { describe, expect, it } from 'vitest'
import { isLoss, marginBadge, marginTone, shareOfRevenue } from './margin'

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

describe('marginBadge', () => {
  // Регрессия: знак брался из тона, а сумма форматировалась исходная,
  // со своим минусом. Убыток выходил «− −500 ₽», а отрицательная
  // нейтральная маржа — «= −500 ₽». Знак и величина теперь приходят
  // одним вызовом и разойтись не могут.
  it('отдаёт величину без знака, знак — отдельно', () => {
    expect(marginBadge(-50_000, 274_700)).toEqual({
      tone: 'negative',
      sign: '−',
      magnitudeMinor: 50_000,
    })
    expect(marginBadge(50_000, 274_700)).toEqual({
      tone: 'positive',
      sign: '+',
      magnitudeMinor: 50_000,
    })
  })

  // Знак идёт от самой величины, а не от тона: маржа может быть
  // отрицательной и при этом нейтральной по порогу, и «=» перед
  // минусом противоречил бы сам себе.
  it('у нейтральной отрицательной маржи знак минуса, а не равенства', () => {
    expect(marginBadge(-500, 274_700)).toEqual({
      tone: 'neutral',
      sign: '−',
      magnitudeMinor: 500,
    })
  })

  it('ровный ноль — равенство', () => {
    expect(marginBadge(0, 274_700)).toEqual({
      tone: 'neutral',
      sign: '=',
      magnitudeMinor: 0,
    })
  })
})
