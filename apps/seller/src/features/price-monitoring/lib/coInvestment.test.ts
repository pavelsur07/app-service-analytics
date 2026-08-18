import { describe, expect, it } from 'vitest'

import { coInvestmentView, observedAgo } from './coInvestment'

describe('представление соинвеста', () => {
  it('считает долю от цены кабинета', () => {
    // Числа из спайка: 2537 в кабинете, 1117 на витрине.
    expect(coInvestmentView(142_000, 253_700)).toEqual({
      percent: 56,
      suspicious: false,
    })
  })

  it('помечает отрицательный соинвест, а не прячет его', () => {
    // Витрина выше кабинета — прочитали не тот узел либо подняли цену
    // между выгрузками. Поджать к нулю значило бы скрыть поломку.
    expect(coInvestmentView(-50_000, 100_000)).toEqual({
      percent: -50,
      suspicious: true,
    })
  })

  it('молчит, когда считать не из чего', () => {
    expect(coInvestmentView(null, 253_700).percent).toBeNull()
    expect(coInvestmentView(142_000, null).percent).toBeNull()
    expect(coInvestmentView(142_000, 0).percent).toBeNull()
  })
})

describe('возраст наблюдения', () => {
  const now = new Date('2026-08-18T12:00:00Z')

  it('называет возраст словами', () => {
    expect(observedAgo('2026-08-18T11:59:40Z', now)).toBe('только что')
    expect(observedAgo('2026-08-18T11:30:00Z', now)).toBe('30 мин назад')
    expect(observedAgo('2026-08-18T09:00:00Z', now)).toBe('3 ч назад')
    expect(observedAgo('2026-08-16T12:00:00Z', now)).toBe('2 дн назад')
  })

  it('отличает «ещё не снимали» от свежего', () => {
    // Артикул отслеживается, но расширение до него не дошло. Показать
    // это как «0 мин назад» значило бы соврать.
    expect(observedAgo(null, now)).toBe('ещё не снимали')
  })
})
