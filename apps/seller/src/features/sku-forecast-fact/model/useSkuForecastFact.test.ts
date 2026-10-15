import { describe, expect, it } from 'vitest'

import {
  skuForecastFactPath,
  skuForecastFactQueryKey,
  skuForecastFactSkusPath,
  skuForecastFactSkusQueryKey,
} from './useSkuForecastFact'

describe('прогноз и факт по SKU', () => {
  it('разделяет кэш по компании, SKU и месяцу', () => {
    const key = skuForecastFactQueryKey('company-a', 'SKU 1', '2026-01')

    expect(key).not.toEqual(
      skuForecastFactQueryKey('company-b', 'SKU 1', '2026-01'),
    )
    expect(key).not.toEqual(
      skuForecastFactQueryKey('company-a', 'SKU 2', '2026-01'),
    )
    expect(key).not.toEqual(
      skuForecastFactQueryKey('company-a', 'SKU 1', '2025-12'),
    )
  })

  it('кодирует SKU в query string', () => {
    expect(skuForecastFactPath('SKU / тест', '2026-01')).toBe(
      '/sku-forecast-fact?sku=SKU+%2F+%D1%82%D0%B5%D1%81%D1%82&month=2026-01',
    )
  })

  it('разделяет поиск по компании, строке и курсору', () => {
    const key = skuForecastFactSkusQueryKey('company-a', 'SKU', null)

    expect(key).not.toEqual(
      skuForecastFactSkusQueryKey('company-b', 'SKU', null),
    )
    expect(key).not.toEqual(
      skuForecastFactSkusQueryKey('company-a', 'ABC', null),
    )
    expect(key).not.toEqual(
      skuForecastFactSkusQueryKey('company-a', 'SKU', 'next'),
    )
    expect(skuForecastFactSkusPath('SKU / тест', 50, 'next')).toBe(
      '/sku-forecast-fact/skus?q=SKU+%2F+%D1%82%D0%B5%D1%81%D1%82&limit=50&cursor=next',
    )
  })
})
