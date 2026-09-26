import { Badge } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { formatLostTime } from '../../../shared/lib/formatLostTime'
import {
  formatCoverDays,
  formatDemand,
  formatOzonRate,
  formatUnits,
  NO_DATA,
  STATUS_PRESENTATION,
} from '../lib/stockPlacementFormat'

type Item = components['schemas']['StockPlacementItemResponse']

const HEAD = 'border-b border-border-default px-4 py-2'
const CELL = 'border-b border-border-subtle px-4 py-3 align-top'
const NUMBER = `${CELL} text-right tabular-nums`

export function StockPlacementTable({
  items,
  demandWindowDays,
}: {
  items: Item[]
  demandWindowDays: number
}) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-280 text-sm">
        <thead>
          <tr className="bg-surface-sunken text-left text-xs font-semibold text-text-secondary">
            <th className={HEAD} scope="col">
              Товар
            </th>
            <th className={HEAD} scope="col">
              Кластер
            </th>
            <th className={HEAD} scope="col">
              Статус
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Доступно
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              В пути · заявлено
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Продано за {demandWindowDays} дн
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Спрос
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Хватит на
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Довезти
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Лишнее ожидание
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Ozon: спрос · дней
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const status = STATUS_PRESENTATION[item.status]

            return (
              <tr key={`${item.marketplaceSku}|${item.cluster}`}>
                <td className={CELL}>
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
                <td className={CELL}>{item.cluster}</td>
                <td className={CELL}>
                  <div className="flex flex-wrap items-center gap-1">
                    <Badge size="compact" tone={status.tone}>
                      {status.label}
                    </Badge>
                    <Badge size="compact" tone="neutral">
                      {item.abcClass}
                    </Badge>
                  </div>
                  {item.zeroDays > 0 ? (
                    <span className="mt-1 block text-xs text-text-muted">
                      без остатка {item.zeroDays} дн
                    </span>
                  ) : null}
                </td>
                <td className={NUMBER}>{formatUnits(item.available)}</td>
                <td className={NUMBER}>
                  {formatUnits(item.transit)} · {formatUnits(item.requested)}
                </td>
                <td className={NUMBER}>{formatUnits(item.sold)}</td>
                <td className={NUMBER}>
                  {formatDemand(item.demandMilliPerDay)}
                </td>
                <td className={NUMBER}>{formatCoverDays(item.coverDays)}</td>
                <td className={`${NUMBER} font-semibold`}>
                  {item.recommended === null || item.recommended === undefined
                    ? NO_DATA
                    : formatUnits(item.recommended)}
                </td>
                <td className={NUMBER}>{formatLostTime(item.lostHours)}</td>
                <td className={`${NUMBER} text-text-muted`}>
                  {formatOzonRate(item.adsCluster)} ·{' '}
                  {formatCoverDays(item.idcCluster)}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
