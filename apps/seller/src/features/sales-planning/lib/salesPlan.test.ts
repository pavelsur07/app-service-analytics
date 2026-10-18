import { describe, expect, it } from 'vitest'
import {
  bucketLabel,
  MAX_PERIOD_DAYS,
  parseQuantity,
  periodDays,
  periodProblem,
  statusLabel,
} from './salesPlan'

describe('период плана', () => {
  it('считает дни включительно', () => {
    expect(periodDays('2026-07-01', '2026-07-31')).toBe(31)
    expect(periodDays('2026-07-15', '2026-07-15')).toBe(1)
    // Переход на зимнее время в браузере не сдвигает счёт: даты в UTC.
    expect(periodDays('2026-10-01', '2026-11-30')).toBe(61)
  })

  it('называет проблему периода до отправки', () => {
    expect(periodProblem('2026-07-01', '2026-07-31')).toBeNull()
    expect(periodProblem('2026-07-02', '2026-07-01')).toBe(
      'Конец периода раньше начала.',
    )
    expect(periodProblem('', '2026-07-01')).toBe('Укажите обе даты.')
  })

  it('пропускает период на пределе и отказывает за ним', () => {
    expect(periodDays('2026-07-01', '2026-12-31')).toBe(MAX_PERIOD_DAYS)
    expect(periodProblem('2026-07-01', '2026-12-31')).toBeNull()
    expect(periodProblem('2026-07-01', '2027-01-01')).toBe(
      'Период — 185 дн., больше 184.',
    )
  })
})

describe('разбор штук', () => {
  it('принимает целое, включая ноль и пробелы-разделители', () => {
    expect(parseQuantity('120')).toBe(120)
    expect(parseQuantity('0')).toBe(0)
    expect(parseQuantity('1 000')).toBe(1000)
  })

  it('отказывает дроби, знаку, мусору и превышению потолка', () => {
    expect(parseQuantity('')).toBeNull()
    expect(parseQuantity('1,5')).toBeNull()
    expect(parseQuantity('-3')).toBeNull()
    expect(parseQuantity('десять')).toBeNull()
    expect(parseQuantity('1000001')).toBeNull()
  })
})

describe('подписи', () => {
  it('корзина — дата или диапазон', () => {
    expect(bucketLabel('2026-07-29', '2026-07-29')).toBe('29.07')
    expect(bucketLabel('2026-07-29', '2026-08-02')).toBe('29.07–02.08')
  })

  it('статус словом, неизвестный — как есть', () => {
    expect(statusLabel('draft')).toBe('Черновик')
    expect(statusLabel('new-status')).toBe('new-status')
  })
})
