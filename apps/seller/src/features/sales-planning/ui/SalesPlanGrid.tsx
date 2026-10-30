import type { ReactNode } from 'react'
import type { components } from '../../../api/schema'
import { formatMinorAmount } from '../../../shared/lib/formatMinorAmount'
import { bucketLabel } from '../lib/salesPlan'
import type { PickedCell } from './salesPlanEditorTypes'

type SalesPlanSku = components['schemas']['SalesPlanSkuResponse']

/**
 * Строки — SKU, колонки — корзины группировки. Дни у всех SKU плана
 * одни и те же (строки плотные, ADR-040 п. 3), поэтому заголовок
 * берётся у первого SKU. При группировке по дням таблица прокручивается.
 */
export function SalesPlanGrid({
  skus,
  currency,
  onPickDay,
  removeAction,
}: {
  skus: SalesPlanSku[]
  currency: string
  onPickDay: ((cell: PickedCell) => void) | null
  removeAction: ((marketplaceSku: string) => ReactNode) | null
}) {
  const columns = skus[0]?.buckets ?? []

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-border-default text-xs text-text-muted">
            <th className="px-3 py-2 text-left font-medium" scope="col">
              SKU
            </th>
            <th className="px-3 py-2 text-right font-medium" scope="col">
              Штуки
            </th>
            <th className="px-3 py-2 text-right font-medium" scope="col">
              Выручка
            </th>
            {columns.map((bucket) => (
              <th
                key={bucket.dateFrom}
                className="px-3 py-2 text-right font-medium whitespace-nowrap"
                scope="col"
              >
                {bucketLabel(bucket.dateFrom, bucket.dateTo)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {skus.map((sku) => (
            <tr
              key={sku.marketplaceSku}
              className="border-b border-border-subtle"
            >
              <th className="px-3 py-2 text-left font-medium" scope="row">
                <span className="flex items-center gap-2">
                  {sku.marketplaceSku}
                  {removeAction?.(sku.marketplaceSku)}
                </span>
              </th>
              <td className="px-3 py-2 text-right tabular-nums">
                {sku.totalQuantity}
              </td>
              <td className="px-3 py-2 text-right tabular-nums whitespace-nowrap">
                {formatMinorAmount(sku.revenueMinor, currency, {
                  wholeUnits: true,
                })}
              </td>
              {sku.buckets.map((bucket) => (
                <td
                  key={bucket.dateFrom}
                  className="px-3 py-2 text-right tabular-nums"
                >
                  {onPickDay === null ? (
                    bucket.quantity
                  ) : (
                    <button
                      className="rounded px-1 hover:bg-surface-hover"
                      type="button"
                      aria-label={`Править ${sku.marketplaceSku} за ${bucket.dateFrom}`}
                      onClick={() => {
                        onPickDay({
                          marketplaceSku: sku.marketplaceSku,
                          date: bucket.dateFrom,
                          quantity: bucket.quantity,
                        })
                      }}
                    >
                      {bucket.quantity}
                    </button>
                  )}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
