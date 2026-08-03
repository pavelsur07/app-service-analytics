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
})
