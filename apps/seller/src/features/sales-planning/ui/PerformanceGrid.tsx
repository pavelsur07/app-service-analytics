import type { components } from '../../../api/schema'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { bucketLabel } from '../lib/salesPlan'
import { PerformanceOrders } from './PerformanceOrders'
import { PerformanceSales } from './PerformanceSales'
import { PerformanceAttainment } from './PerformanceAttainment'
import { PerformanceQuality } from './PerformanceQuality'
import { PerformanceBuyout } from './PerformanceBuyout'
import { PerformanceForecast } from './PerformanceForecast'
import { moscowToday } from '../lib/performanceWindow'

export function PerformanceGrid({
  data,
  mode = 'period',
}: {
  mode?: 'period' | 'cumulative'
  data: components['schemas']['SalesPlanPerformanceResponse']
}) {
  const columns = data.skus[0]?.points ?? []
  const today = moscowToday(new Date(data.calculatedAt))
  if (data.skus.length === 0) return <p>В выбранной странице нет SKU.</p>
  return (
    <div
      className="overflow-x-auto focus-visible:outline-2 focus-visible:outline-accent-default"
      role="region"
      aria-label="Сравнение SKU по датам"
      tabIndex={0}
    >
      <table className="w-full text-sm">
        <caption className="pb-3 text-left text-text-secondary">
          Исходная цель и наблюдаемые результаты. Только эта страница SKU.
        </caption>
        <thead>
          <tr className="border-b border-border-default">
            <th
              className="sticky left-0 z-10 bg-surface-raised px-3 py-2 text-left"
              scope="col"
            >
              SKU
            </th>
            {columns.map((point) => (
              <th
                key={point.bucketFrom}
                scope="col"
                className="px-3 py-2 text-left whitespace-nowrap"
              >
                {bucketLabel(point.bucketFrom, point.bucketTo)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.skus.map((sku) => (
            <tr
              key={sku.marketplaceSku}
              className="border-b border-border-subtle"
            >
              <th
                scope="row"
                className="sticky left-0 z-10 bg-surface-raised px-3 py-3 text-left align-top"
              >
                {sku.marketplaceSku}
              </th>
              {sku.points.map((point) => {
                const value = point[mode]
                return (
                  <td key={point.bucketFrom} className="px-3 py-3 align-top">
                    <div className="flex flex-col gap-4">
                      <PerformanceQuality
                        coverage={
                          mode === 'period'
                            ? point.periodCoverage
                            : point.cumulativeCoverage
                        }
                        current={
                          point.bucketFrom <= today && point.bucketTo >= today
                        }
                        future={point.bucketFrom > today}
                      />
                      <dl className="tabular-nums">
                        <dt className="font-semibold">Исходная цель</dt>
                        <dd>{value.planQuantity} шт.</dd>
                        <dt className="text-text-muted">
                          Плановая выручка по РРЦ
                        </dt>
                        <dd>
                          {formatMinorAmount(
                            value.planRevenueRrp.minor,
                            value.planRevenueRrp.currency,
                          )}
                        </dd>
                      </dl>
                      <PerformanceOrders cohort={value.cohort} />
                      <PerformanceSales value={value} />
                      <PerformanceAttainment value={value} />
                      <PerformanceBuyout cohort={value.cohort} />
                      <PerformanceForecast cohort={value.cohort} />
                    </div>
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
