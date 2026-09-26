import { describe, expect, it } from 'vitest'

import { formatBasisPoints } from './formatBasisPoints'

describe('formatBasisPoints', () => {
  it('показывает целые и дробные проценты без хвостовых нулей', () => {
    expect(formatBasisPoints(5000)).toBe('50%')
    expect(formatBasisPoints(4546)).toBe('45,46%')
    expect(formatBasisPoints(3710)).toBe('37,1%')
    expect(formatBasisPoints(7)).toBe('0,07%')
    expect(formatBasisPoints(0)).toBe('0%')
    expect(formatBasisPoints(10000)).toBe('100%')
  })

  it('ставит типографский минус у отрицательных', () => {
    expect(formatBasisPoints(-125)).toBe('−1,25%')
  })
})
