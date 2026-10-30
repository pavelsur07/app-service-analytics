import type { components } from '../../../api/schema'
import {
  PerformanceMetricRow,
  PerformanceMoneyRow,
} from './PerformanceMetricRow'
import { bucketLabel } from '../lib/salesPlan'
import { PerformanceOrders } from './PerformanceOrders'
import { PerformanceSales } from './PerformanceSales'
import { PerformanceAttainment } from './PerformanceAttainment'
import { PerformanceQuality } from './PerformanceQuality'
import { PerformanceBuyout } from './PerformanceBuyout'
import { PerformanceForecast } from './PerformanceForecast'
import { PerformanceNetForecast } from './PerformanceNetForecast'
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
      <table className="w-full table-fixed text-sm">
        <caption className="pb-3 text-left text-text-secondary">
          <span className="sticky left-0 inline-block">
            Только эта страница SKU.
          </span>
        </caption>
        <colgroup>
          <col className="w-48 sm:w-64" />
          {columns.map((point) => (
            <col key={point.bucketFrom} className="w-44" />
          ))}
        </colgroup>
        <thead>
          <tr className="border-b border-border-default">
            <th
              className="sticky left-0 z-10 bg-surface-raised px-3 py-2 text-left"
              scope="col"
            >
              Показатель
            </th>
            {columns.map((point) => (
              <th
                key={point.bucketFrom}
                scope="col"
                className="px-3 py-2 text-right whitespace-nowrap"
              >
                {bucketLabel(point.bucketFrom, point.bucketTo)}
              </th>
            ))}
          </tr>
        </thead>
        {data.skus.map((sku) => {
          const values = sku.points.map((point) => ({
            key: point.bucketFrom,
            value: point[mode],
          }))
          return (
            <tbody
              key={sku.marketplaceSku}
              aria-label={`SKU ${sku.marketplaceSku}`}
            >
              <tr className="border-b border-border-default bg-surface-raised">
                <th
                  scope="rowgroup"
                  className="sticky left-0 z-10 bg-surface-raised px-3 py-3 text-left font-semibold"
                >
                  SKU {sku.marketplaceSku}
                </th>
                {columns.length > 0 && <td colSpan={columns.length} />}
              </tr>
              <PerformanceMetricRow label="Покрытие заказов" columns={values}>
                {(_, index) => {
                  const point = sku.points[index]
                  return point === undefined ? (
                    '—'
                  ) : (
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
                  )
                }}
              </PerformanceMetricRow>
              <PerformanceMetricRow
                label="Исходная цель"
                unit="шт."
                columns={values}
              >
                {(value) => value.planQuantity}
              </PerformanceMetricRow>
              <PerformanceMoneyRow
                label="Плановая выручка по РРЦ"
                columns={values}
                currency={data.currency}
                amount={(value) => value.planRevenueRrp}
              />
              <PerformanceOrders columns={values} />
              <PerformanceSales columns={values} currency={data.currency} />
              <PerformanceAttainment
                columns={values}
                currency={data.currency}
              />
              <PerformanceBuyout columns={values} />
              <PerformanceForecast columns={values} />
              <PerformanceNetForecast
                columns={values}
                currency={data.currency}
              />
            </tbody>
          )
        })}
      </table>
    </div>
  )
}
