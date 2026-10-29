import { describe, expect, it } from 'vitest'
import { formatMinorAmountExact } from './formatMinorAmountExact'

const spaces = (value: string) => value.replace(/[\u00a0\u202f]/g, ' ')

describe('exact minor amount presentation', () => {
  it.each([
    ['9223372036854775807', '92 233 720 368 547 758,07 ₽'],
    ['-9223372036854775808', '-92 233 720 368 547 758,08 ₽'],
  ])('preserves every native integer digit: %s', (minor, expected) => {
    expect(spaces(formatMinorAmountExact(minor, 'RUB', 2))).toBe(expected)
  })

  it.each([
    ['JPY', 0, ['0 ¥', '1 ¥', '-1 ¥']],
    ['RUB', 2, ['0,00 ₽', '0,01 ₽', '-0,01 ₽']],
    ['KWD', 3, ['0,000 KWD', '0,001 KWD', '-0,001 KWD']],
    ['CLF', 4, ['0,0000 CLF', '0,0001 CLF', '-0,0001 CLF']],
    ['RUB', 1, ['0,0 ₽', '0,1 ₽', '-0,1 ₽']],
    ['CODE', 4, ['0,0000 CODE', '0,0001 CODE', '-0,0001 CODE']],
  ] as const)(
    'uses the supplied scale for %s, including negative fractional zero',
    (currency, digits, expected) => {
      for (const [index, minor] of ['0', '1', '-1'].entries()) {
        expect(spaces(formatMinorAmountExact(minor, currency, digits))).toBe(
          expected[index],
        )
      }
    },
  )

  it('preserves grouped amounts with an unfamiliar client currency', () => {
    expect(
      spaces(formatMinorAmountExact('-9223372036854775808', 'CODE', 4)),
    ).toBe('-922 337 203 685 477,5808 CODE')
  })

  it.each(['', '-0', '00', '01', '-01', '+1', '1e3', '1.2', ' 1', '1 '])(
    'refuses noncanonical minor %j without guessing an amount',
    (minor) => {
      expect(formatMinorAmountExact(minor, 'RUB', 2)).toBe('—')
    },
  )

  it.each([-1, 5, 1.5, NaN, Infinity])(
    'refuses invalid exponent %s',
    (digits) => {
      expect(formatMinorAmountExact('123', 'RUB', digits)).toBe('—')
    },
  )

  it('refuses an absent currency', () => {
    expect(formatMinorAmountExact('1', '', 2)).toBe('—')
  })
})
