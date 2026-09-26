import { Badge } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import {
  formatPerUnit,
  formatQuantity,
  formatShare,
  formatSources,
} from '../lib/localizationFormat'

type ClusterRow = components['schemas']['LocalizationClusterResponse']
type SkuRow = components['schemas']['LocalizationSkuResponse']
type Metrics = components['schemas']['LocalizationMetricsResponse']

const HEAD = 'border-b border-border-default px-4 py-2'
const CELL = 'border-b border-border-subtle px-4 py-3 align-top'
const NUMBER = `${CELL} text-right tabular-nums`

// Строка ниже порога приглушена: цифры в ней есть, но выводов из них
// не делают — доли и логистику на штуку бэкенд уже не отдал.
function rowClass(metrics: Metrics): string {
  return metrics.sufficientData ? '' : 'text-text-muted'
}

function LowDataBadge({ metrics }: { metrics: Metrics }) {
  return metrics.sufficientData ? null : (
    <Badge size="compact" tone="neutral">
      мало данных
    </Badge>
  )
}

export function LocalizationClusterTable({ items }: { items: ClusterRow[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-200 text-sm">
        <thead>
          <tr className="bg-surface-sunken text-left text-xs font-semibold text-text-secondary">
            <th className={HEAD} scope="col">
              Кластер доставки
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Продано
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Локальных
            </th>
            <th className={HEAD} scope="col">
              Откуда везли
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Логистика локальных
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Логистика нелокальных
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr className={rowClass(item.metrics)} key={item.clusterTo}>
              <td className={CELL}>
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{item.clusterTo}</span>
                  <LowDataBadge metrics={item.metrics} />
                </div>
              </td>
              <td className={NUMBER}>
                {formatQuantity(item.metrics.clusteredQuantity)}
              </td>
              <td className={NUMBER}>
                {formatShare(item.metrics.localShareBps)}
              </td>
              <td className={CELL}>{formatSources(item.topSources)}</td>
              <td className={NUMBER}>
                {formatPerUnit(
                  item.metrics.localForwardCostPerUnitMinor,
                  item.metrics.currency,
                )}
              </td>
              <td className={NUMBER}>
                {formatPerUnit(
                  item.metrics.nonlocalForwardCostPerUnitMinor,
                  item.metrics.currency,
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function LocalizationSkuTable({ items }: { items: SkuRow[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-240 text-sm">
        <thead>
          <tr className="bg-surface-sunken text-left text-xs font-semibold text-text-secondary">
            <th className={HEAD} scope="col">
              Товар
            </th>
            <th className={HEAD} scope="col">
              Кластер доставки
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Нелокальных
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Продано
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Локальных
            </th>
            <th className={HEAD} scope="col">
              Чаще всего везли из
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Логистика лок. / нелок.
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr
              className={rowClass(item.metrics)}
              key={`${item.marketplaceSku}|${item.clusterTo}`}
            >
              <td className={CELL}>
                <div className="flex flex-col gap-0.5">
                  <span className="font-medium">
                    {item.offerId ?? item.marketplaceSku}
                  </span>
                  {item.offerId === null || item.offerId === undefined ? (
                    // Карточки ещё нет: жирным уже стоит SKU, второй раз
                    // его не повторяем.
                    item.name ? (
                      <span className="text-xs text-text-muted">
                        {item.name}
                      </span>
                    ) : null
                  ) : (
                    <span className="text-xs text-text-muted">
                      {item.name ?? `SKU ${item.marketplaceSku}`}
                    </span>
                  )}
                </div>
              </td>
              <td className={CELL}>
                <div className="flex flex-wrap items-center gap-2">
                  <span>{item.clusterTo}</span>
                  <LowDataBadge metrics={item.metrics} />
                </div>
              </td>
              <td className={`${NUMBER} font-semibold`}>
                {formatQuantity(item.metrics.nonlocalQuantity)}
              </td>
              <td className={NUMBER}>
                {formatQuantity(item.metrics.clusteredQuantity)}
              </td>
              <td className={NUMBER}>
                {formatShare(item.metrics.localShareBps)}
              </td>
              <td className={CELL}>{item.mainSourceCluster}</td>
              <td className={NUMBER}>
                {formatPerUnit(
                  item.metrics.localForwardCostPerUnitMinor,
                  item.metrics.currency,
                )}
                {' / '}
                {formatPerUnit(
                  item.metrics.nonlocalForwardCostPerUnitMinor,
                  item.metrics.currency,
                )}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
