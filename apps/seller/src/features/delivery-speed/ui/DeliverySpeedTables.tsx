import { Badge } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import { formatBasisPoints } from '../../../shared/lib/formatBasisPoints'
import {
  formatBucket,
  formatDuration,
  formatLostTime,
  NO_DATA,
} from '../lib/deliverySpeedFormat'

type ClusterRow = components['schemas']['DeliverySpeedClusterResponse']
type RouteRow = components['schemas']['DeliverySpeedRouteResponse']
type SkuRow = components['schemas']['DeliverySpeedSkuResponse']
type Bucket = components['schemas']['DeliverySpeedBucketResponse']
type Metrics = components['schemas']['DeliverySpeedMetricsResponse']

const HEAD = 'border-b border-border-default px-4 py-2'
const CELL = 'border-b border-border-subtle px-4 py-3 align-top'
const NUMBER = `${CELL} text-right tabular-nums`
const QUANTITY = new Intl.NumberFormat('ru-RU')

function LowDataBadge({ metrics }: { metrics: Metrics }) {
  return metrics.sufficientData ? null : (
    <Badge size="compact" tone="neutral">
      мало данных
    </Badge>
  )
}

export function DeliverySpeedClusterTable({ items }: { items: ClusterRow[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-200 text-sm">
        <thead>
          <tr className="bg-surface-sunken text-left text-xs font-semibold text-text-secondary">
            <th className={HEAD} scope="col">
              Кластер доставки
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Доставлено
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Медиана локальных
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Медиана нелокальных
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Покупатели прождали лишнего
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr
              className={item.metrics.sufficientData ? '' : 'text-text-muted'}
              key={item.clusterTo}
            >
              <td className={CELL}>
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-medium">{item.clusterTo}</span>
                  <LowDataBadge metrics={item.metrics} />
                </div>
              </td>
              <td className={NUMBER}>
                {QUANTITY.format(item.metrics.arrivedPostings)}
              </td>
              <td className={NUMBER}>
                {formatDuration(item.metrics.medianLocalSeconds)}
              </td>
              <td className={NUMBER}>
                {formatDuration(item.metrics.medianNonlocalSeconds)}
              </td>
              <td className={`${NUMBER} font-semibold`}>
                {formatLostTime(item.lostHours)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function DeliverySpeedRouteTable({ items }: { items: RouteRow[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-200 text-sm">
        <thead>
          <tr className="bg-surface-sunken text-left text-xs font-semibold text-text-secondary">
            <th className={HEAD} scope="col">
              Маршрут
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Доставлено
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Медиана
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              9 из 10 быстрее
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr
              className={item.metrics.sufficientData ? '' : 'text-text-muted'}
              key={`${item.clusterFrom}|${item.clusterTo}`}
            >
              <td className={CELL}>
                <div className="flex flex-wrap items-center gap-2">
                  <span>
                    {item.clusterFrom} → {item.clusterTo}
                  </span>
                  {item.local ? (
                    <Badge size="compact" tone="positive">
                      локально
                    </Badge>
                  ) : null}
                  <LowDataBadge metrics={item.metrics} />
                </div>
              </td>
              <td className={NUMBER}>
                {QUANTITY.format(item.metrics.arrivedPostings)}
              </td>
              <td className={NUMBER}>
                {formatDuration(item.metrics.medianDeliverySeconds)}
              </td>
              <td className={NUMBER}>
                {formatDuration(item.metrics.p90DeliverySeconds)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function DeliverySpeedSkuTable({ items }: { items: SkuRow[] }) {
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
              Нелокальных отправлений
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Доставка лок. / нелок.
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Покупатели прождали лишнего
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={`${item.marketplaceSku}|${item.clusterTo}`}>
              <td className={CELL}>
                <div className="flex flex-col gap-0.5">
                  <span className="font-medium">
                    {item.offerId ?? item.marketplaceSku}
                  </span>
                  {item.name ? (
                    <span className="text-xs text-text-muted">{item.name}</span>
                  ) : null}
                </div>
              </td>
              <td className={CELL}>{item.clusterTo}</td>
              <td className={NUMBER}>
                {QUANTITY.format(item.nonlocalArrivedPostings)}
              </td>
              <td className={NUMBER}>
                {formatDuration(item.clusterMedianLocalSeconds)}
                {' / '}
                {formatDuration(item.clusterMedianNonlocalSeconds)}
              </td>
              <td className={`${NUMBER} font-semibold`}>
                {formatLostTime(item.lostHours)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function DeliverySpeedBuyoutTable({ items }: { items: Bucket[] }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-160 text-sm">
        <thead>
          <tr className="bg-surface-sunken text-left text-xs font-semibold text-text-secondary">
            <th className={HEAD} scope="col">
              Дней до прибытия
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Отправлений
            </th>
            <th className={`${HEAD} text-right`} scope="col">
              Выкуп
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.minDays}>
              <td className={CELL}>{formatBucket(item)}</td>
              <td className={NUMBER}>{QUANTITY.format(item.postings)}</td>
              <td className={`${NUMBER} font-semibold`}>
                {item.buyoutRateBps === null || item.buyoutRateBps === undefined
                  ? NO_DATA
                  : formatBasisPoints(item.buyoutRateBps)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}
