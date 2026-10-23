import { describe, expect, it } from 'vitest'

import {
  cellTitle,
  formatShare,
  formatTrend,
  heatLevel,
  trendTone,
} from './demandMatrixFormat'

describe('форматирование матрицы спроса', () => {
  it('тренд — изменение к 28 дням со знаком', () => {
    expect(formatTrend(16000)).toBe('+60%')
    expect(formatTrend(8000)).toBe('−20%')
    expect(formatTrend(10000)).toBe('0%')
    expect(formatTrend(null)).toBe('—')
  })

  it('направление тренда с полосой ±10%', () => {
    expect(trendTone(11000)).toBe('up')
    expect(trendTone(10999)).toBe('flat')
    expect(trendTone(9001)).toBe('flat')
    expect(trendTone(9000)).toBe('down')
    expect(trendTone(null)).toBe('none')
  })

  it('доля кластера', () => {
    expect(formatShare(7500)).toBe('75%')
    expect(formatShare(null)).toBe('—')
  })

  it('ступени фона по доле', () => {
    expect(heatLevel(null)).toBe(0)
    expect(heatLevel(999)).toBe(0)
    expect(heatLevel(1000)).toBe(1)
    expect(heatLevel(2499)).toBe(1)
    expect(heatLevel(2500)).toBe(2)
    expect(heatLevel(5000)).toBe(3)
  })

  it('подсказка называет дефицит', () => {
    const base = { orders7: 1, orders14: 2, orders28: 4, trendBps: null }

    expect(cellTitle({ ...base, stockoutDays: 0 })).not.toContain('занижен')
    expect(cellTitle({ ...base, stockoutDays: 5 })).toContain(
      'Без товара в кластере 5 дн — спрос занижен',
    )
  })
})
