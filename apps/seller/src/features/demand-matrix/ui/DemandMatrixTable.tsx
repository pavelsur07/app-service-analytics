import { PackageX, TrendingDown, TrendingUp } from 'lucide-react'

import type { components } from '../../../api/schema'
import { formatDemand } from '../../../shared/lib/formatDemand'
import {
  cellTitle,
  formatShare,
  formatTrend,
  heatLevel,
  type HeatLevel,
  trendTone,
} from '../lib/demandMatrixFormat'

type Item = components['schemas']['DemandMatrixItemResponse']
type Cell = components['schemas']['DemandMatrixCellResponse']
type Cluster = components['schemas']['DemandMatrixClusterResponse']

const QUANTITY = new Intl.NumberFormat('ru-RU')
const HEAD = 'border-b border-border-default px-3 py-2'
const CELL = 'border-b border-border-subtle px-3 py-2 align-top'
const NUMBER = `${CELL} text-right tabular-nums`
// Заливка по доле кластера в спросе товара — четыре ступени акцента.
const HEAT: Record<HeatLevel, string> = {
  0: '',
  1: 'bg-accent-default/10',
  2: 'bg-accent-default/20',
  3: 'bg-accent-default/35',
}

export function DemandMatrixTable({
  items,
  clusters,
  minSales,
  windowDays,
}: {
  items: Item[]
  clusters: Cluster[]
  minSales: number
  windowDays: number
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="bg-surface-sunken text-left text-xs font-semibold text-text-secondary">
            <th
              className={`${HEAD} sticky left-0 z-10 min-w-56 bg-surface-sunken`}
              scope="col"
            >
              Товар
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Заказы {windowDays} дн
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Тренд 7 к 28
            </th>
            {clusters.map((cluster) => (
              <th
                className={`${HEAD} min-w-28 text-right`}
                key={cluster.cluster ?? ''}
                scope="col"
                title={`Заказов за ${windowDays} дн: ${QUANTITY.format(cluster.orders28)}`}
              >
                {cluster.cluster ?? 'Кластер не указан'}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const cells = new Map<string | null, Cell>(
              item.cells.map((cell) => [cell.cluster ?? null, cell]),
            )

            return (
              <tr key={item.marketplaceSku}>
                <td className={`${CELL} sticky left-0 z-10 bg-surface-raised`}>
                  <div className="flex flex-col gap-0.5">
                    <span className="font-medium">
                      {item.offerId ?? item.marketplaceSku}
                    </span>
                    {item.name ? (
                      <span className="text-xs text-text-muted">
                        {item.name}
                      </span>
                    ) : null}
                  </div>
                </td>
                <td className={NUMBER}>
                  <div className="flex flex-col">
                    <span className="font-semibold">
                      {QUANTITY.format(item.orders28)}
                    </span>
                    <span className="text-xs text-text-muted">
                      {formatDemand(item.demandMilliPerDay)}
                    </span>
                  </div>
                </td>
                <td
                  className={NUMBER}
                  title={
                    item.trendBps === null || item.trendBps === undefined
                      ? `Меньше ${minSales} заказов за ${windowDays} дн — тренд не считается`
                      : `Заказы: 7 дн — ${item.orders7}, ${windowDays} дн — ${item.orders28}`
                  }
                >
                  <Trend trendBps={item.trendBps} />
                </td>
                {clusters.map((cluster) => (
                  <MatrixCell
                    cell={cells.get(cluster.cluster ?? null)}
                    key={cluster.cluster ?? ''}
                  />
                ))}
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}

function Trend({ trendBps }: { trendBps: number | null | undefined }) {
  const tone = trendTone(trendBps)
  const color =
    tone === 'up'
      ? 'text-positive-text'
      : tone === 'down'
        ? 'text-negative-text'
        : 'text-text-muted'

  return (
    <span className={`inline-flex items-center justify-end gap-1 ${color}`}>
      {tone === 'up' ? <TrendingUp aria-hidden="true" size={14} /> : null}
      {tone === 'down' ? <TrendingDown aria-hidden="true" size={14} /> : null}
      {formatTrend(trendBps)}
    </span>
  )
}

function MatrixCell({ cell }: { cell: Cell | undefined }) {
  if (cell === undefined) {
    return <td className={NUMBER} />
  }
  const censored = cell.stockoutDays > 0

  // Ячейка без заказов, где товара не было, — «спрос неизвестен»,
  // а не ноль: где товара нет, там нет и продаж.
  if (cell.orders28 === 0) {
    return (
      <td className={`${NUMBER} text-warning-text`} title={cellTitle(cell)}>
        <span className="inline-flex items-center justify-end gap-1">
          <PackageX aria-hidden="true" size={14} />?
        </span>
        <span className="block text-xs">без товара {cell.stockoutDays} дн</span>
      </td>
    )
  }

  return (
    <td
      className={`${NUMBER} ${HEAT[heatLevel(cell.shareBps)]}`}
      title={cellTitle(cell)}
    >
      <span className="inline-flex items-center justify-end gap-1 font-medium">
        {censored ? (
          <PackageX
            aria-label="спрос занижен: товара не было"
            className="text-warning-text"
            size={14}
          />
        ) : null}
        {formatDemand(cell.demandMilliPerDay)}
      </span>
      <span className="block text-xs text-text-muted">
        {formatShare(cell.shareBps)}
        {censored ? ` · без товара ${cell.stockoutDays} дн` : ''}
      </span>
    </td>
  )
}
