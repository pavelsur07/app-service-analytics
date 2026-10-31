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
  if (data.skus.length === 0) return <p>В выбранной странице нет SKU.</p>
  return (
    <div className="flex flex-col gap-3">
      <PerformanceTable data={data} mode={mode} />
      <details>
        <summary className="cursor-pointer text-sm focus-visible:outline-2 focus-visible:outline-accent-default">
          Подробности заказов, выкупа и качества данных
        </summary>
        <PerformanceTable data={data} mode={mode} detailed />
      </details>
    </div>
  )
}

function PerformanceTable({
  data,
  mode,
  detailed = false,
}: {
  mode: 'period' | 'cumulative'
  detailed?: boolean
  data: components['schemas']['SalesPlanPerformanceResponse']
}) {
  const columns = data.skus[0]?.points ?? []
  const today = moscowToday(new Date(data.calculatedAt))
  if (data.skus.length === 0) return <p>В выбранной странице нет SKU.</p>
  return (
    <div
      className="overflow-x-auto focus-visible:outline-2 focus-visible:outline-accent-default"
      role="region"
      aria-label={
        detailed
          ? 'Подробные показатели SKU по датам'
          : 'Сравнение SKU по датам'
      }
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
              {detailed ? (
                <>
                  <PerformanceMetricRow
                    label="Полнота данных о заказах"
                    columns={values}
                  >
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
                  <PerformanceOrders columns={values} />
                  {data.metric !== 'retained_sales' && (
                    <PerformanceSales
                      columns={values}
                      currency={data.currency}
                    />
                  )}
                  <PerformanceBuyout columns={values} />
                  <PerformanceForecast columns={values} />
                </>
              ) : (
                <>
                  <PerformanceMetricRow
                    label={
                      data.metric === 'retained_sales'
                        ? 'План продаж'
                        : 'План заказов'
                    }
                    unit="шт."
                    columns={values}
                    description={
                      <p>
                        Исходный утверждённый план. Изменение текущей версии не
                        меняет базу сравнения.
                      </p>
                    }
                  >
                    {(value) => value.planQuantity}
                  </PerformanceMetricRow>
                  <PerformanceMoneyRow
                    label="Плановая стоимость по РРЦ"
                    description={
                      <p>
                        Стоимость планового количества по рекомендованной
                        розничной цене. Это не прогноз выручки.
                      </p>
                    }
                    columns={values}
                    currency={data.currency}
                    amount={(value) => value.planRevenueRrp}
                  />
                  <PerformanceMetricRow
                    label={
                      data.metric === 'retained_sales'
                        ? 'Факт продаж'
                        : 'Факт заказов'
                    }
                    unit="шт."
                    columns={values}
                    description={
                      <p>
                        {data.metric === 'retained_sales'
                          ? 'Продажи за вычетом уже состоявшихся возвратов.'
                          : 'Заказанные товары за вычетом отмен до передачи в доставку.'}
                      </p>
                    }
                  >
                    {(value) => value.actualGoalQuantity ?? '—'}
                  </PerformanceMetricRow>
                  <PerformanceMoneyRow
                    label="Выручка за вычетом возвратов"
                    columns={values}
                    currency={data.currency}
                    amount={({ cohort }) => cohort?.observedRevenue}
                  />
                  <PerformanceAttainment
                    columns={values}
                    currency={data.currency}
                    metric={data.metric}
                  />
                  <PerformanceNetForecast
                    columns={values}
                    currency={data.currency}
                  />
                </>
              )}
            </tbody>
          )
        })}
      </table>
    </div>
  )
}
