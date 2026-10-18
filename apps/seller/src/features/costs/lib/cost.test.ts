import { describe, expect, it } from 'vitest'
import { correctionImpact, listingsWithoutCost } from './cost'

describe('сколько карточек без цены', () => {
  it('считает разницу', () => {
    expect(listingsWithoutCost(62, 8)).toBe(54)
  })

  it('не уходит в минус на рассогласованных числах', () => {
    expect(listingsWithoutCost(5, 9)).toBe(0)
  })
})

describe('что затронет исправление', () => {
  const today = '2026-08-15'

  it('считает дни включительно', () => {
    // Цена с 4 августа по 15-е — это 12 дней, а не 11: день, с которого
    // цена действует, тоже её день.
    expect(correctionImpact('2026-08-04', 47, today)).toEqual({
      days: 12,
      units: 47,
    })
  })

  it('цена, заведённая сегодня, действует один день, а не ноль', () => {
    expect(correctionImpact('2026-08-15', 0, today).days).toBe(1)
  })

  it('на неразбираемой дате не падает и не врёт числом', () => {
    expect(correctionImpact('', 3, today)).toEqual({ days: 0, units: 3 })
  })
})
