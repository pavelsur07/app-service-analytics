import { describe, expect, it } from 'vitest'

import {
  daysOptions,
  parseStockPlacementView,
  stockPlacementSearchWithCursor,
  stockPlacementSearchWithView,
} from './stockPlacementParams'

describe('параметры экрана остатков', () => {
  it('без параметров — умолчания сервера', () => {
    expect(parseStockPlacementView(new URLSearchParams())).toEqual({
      targetDays: 28,
      leadDays: 7,
      status: null,
    })
  })

  it('значение вне границ сервера или не целое — умолчание, а не 422', () => {
    expect(
      parseStockPlacementView(
        new URLSearchParams('target_days=6&lead_days=61&status=lost'),
      ),
    ).toEqual({ targetDays: 28, leadDays: 7, status: null })
    expect(
      parseStockPlacementView(
        new URLSearchParams('target_days=1.5&lead_days=-1'),
      ),
    ).toEqual({ targetDays: 28, leadDays: 7, status: null })
    expect(
      parseStockPlacementView(
        new URLSearchParams('target_days=90&lead_days=0&status=deficit'),
      ),
    ).toEqual({ targetDays: 90, leadDays: 0, status: 'deficit' })
  })

  it('смена расчёта сбрасывает курсор, чужой курсор сервер отвергнет', () => {
    const next = stockPlacementSearchWithView(
      new URLSearchParams('status=deficit&cursor=abc'),
      { targetDays: 42, leadDays: 14, status: null },
    )

    expect(next.toString()).toBe('target_days=42&lead_days=14')
  })

  it('курсор пишется и снимается, параметры расчёта остаются', () => {
    const current = new URLSearchParams('target_days=42')

    expect(stockPlacementSearchWithCursor(current, 'x').toString()).toBe(
      'target_days=42&cursor=x',
    )
    expect(
      stockPlacementSearchWithCursor(
        new URLSearchParams('target_days=42&cursor=x'),
        null,
      ).toString(),
    ).toBe('target_days=42')
  })

  it('значение из ссылки вне предустановок попадает в список по порядку', () => {
    expect(daysOptions([14, 28], 28)).toEqual([14, 28])
    expect(daysOptions([14, 28], 20)).toEqual([14, 20, 28])
  })
})
