import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'

import { SkuForecastFactTable } from './SkuForecastFactTable'

describe('дневной отчёт по SKU', () => {
  it('показывает нулевой день без процента и прогноза, сохраняя факт', () => {
    const html = renderToStaticMarkup(
      <SkuForecastFactTable
        currency="RUB"
        days={[
          {
            date: '2026-01-02',
            orderedAmountMinor: 0,
            orderedQuantity: 0,
            plannedBuyoutRateBps: null,
            forecastRevenueMinor: null,
            actualRevenueMinor: 0,
          },
          {
            date: '2026-01-01',
            orderedAmountMinor: 10150,
            orderedQuantity: 2,
            plannedBuyoutRateBps: 7500,
            forecastRevenueMinor: 7650,
            actualRevenueMinor: 5050,
          },
        ]}
      />,
    )

    expect(html).toContain('02.01.2026')
    expect(html).toContain('01.01.2026')
    expect(html).toContain('101,50')
    expect(html).toContain('75%')
    expect(html).toContain('76,50')
    expect(html).toContain('50,50')
    expect(html).toContain('—')
  })
})
