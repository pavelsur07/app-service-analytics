import { describe, expect, it } from 'vitest'
import {
  canGoBack,
  canGoForward,
  currentMonth,
  monthFromParam,
  monthLabel,
  shiftMonth,
} from './month'

describe('переключатель месяца', () => {
  it('листает через границу года в обе стороны', () => {
    expect(shiftMonth('2026-09', -1)).toBe('2026-08')
    expect(shiftMonth('2026-01', -1)).toBe('2025-12')
    expect(shiftMonth('2026-12', 1)).toBe('2027-01')
  })

  it('подписывает месяц по-русски', () => {
    expect(monthLabel('2026-09')).toBe('сентябрь 2026')
  })

  it('не пускает в будущий месяц', () => {
    expect(canGoForward('2026-08', '2026-09')).toBe(true)
    expect(canGoForward('2026-09', '2026-09')).toBe(false)
    expect(canGoBack('2020-02')).toBe(true)
    expect(canGoBack('2020-01')).toBe(false)
  })

  it('берёт из адреса только месяц, который примет API, иначе текущий', () => {
    expect(monthFromParam('2026-08', '2026-09')).toBe('2026-08')
    expect(monthFromParam('2020-01', '2026-09')).toBe('2020-01')
    expect(monthFromParam(null, '2026-09')).toBe('2026-09')
    expect(monthFromParam('2026-13', '2026-09')).toBe('2026-09')
    expect(monthFromParam('2026-00', '2026-09')).toBe('2026-09')
    expect(monthFromParam('2019-12', '2026-09')).toBe('2026-09')
    expect(monthFromParam('2026-10', '2026-09')).toBe('2026-09')
    expect(monthFromParam('сентябрь', '2026-09')).toBe('2026-09')
  })

  it('вместо неверного месяца отдаёт заданное умолчание', () => {
    // Сверка открывается прошлым месяцем: его сверяют с выгрузкой кабинета.
    expect(monthFromParam(null, '2026-09', '2026-08')).toBe('2026-08')
    expect(monthFromParam('2026-10', '2026-09', '2026-08')).toBe('2026-08')
    expect(monthFromParam('2026-07', '2026-09', '2026-08')).toBe('2026-07')
  })

  it('считает текущий месяц по Москве, а не по часам браузера', () => {
    // 31 августа 22:30 UTC — в Москве уже 1 сентября.
    expect(currentMonth(new Date('2026-08-31T22:30:00Z'))).toBe('2026-09')
  })
})
