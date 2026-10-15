import { describe, expect, it } from 'vitest'
import { formatMinorAmount } from './formatMinorAmount'

// Intl.NumberFormat вставляет неразрывный пробел (U+00A0) между суммой
// и символом валюты, не обычный U+0020 — в строках ниже это он, не опечатка.
describe('formatMinorAmount', () => {
  it('formats minor units as a currency string', () => {
    expect(formatMinorAmount(10050, 'RUB')).toBe('100,50 ₽')
  })

  it('keeps whole amounts at two decimal places', () => {
    expect(formatMinorAmount(500, 'USD')).toBe('5,00 $')
  })

  it('shows whole units when asked, rounding only the display', () => {
    expect(formatMinorAmount(12_345_678, 'RUB', { wholeUnits: true })).toBe(
      '123\u00a0457\u00a0₽',
    )
    expect(formatMinorAmount(10050, 'RUB', { wholeUnits: true })).toBe(
      '101\u00a0₽',
    )
  })
})
