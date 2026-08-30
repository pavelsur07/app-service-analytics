import { CircleX, LineChart as LineChartIcon } from 'lucide-react'
import {
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'

import { Button, Card, StatusPanel } from '../../../../../../packages/ui/src'
import type { components } from '../../../api/schema'
import type { BuyoutDays } from '../lib/buyoutParams'
import { countAvailableRateDays } from '../lib/buyoutDailySeries'
import { formatRateBps } from '../lib/buyoutStatusPresentation'
import { useBuyoutDaily } from '../model/useBuyoutDaily'

type DailyPoint = components['schemas']['BuyoutDailyPointResponse']

const QUANTITY = new Intl.NumberFormat('ru-RU')

export function SkuBuyoutDaily({
  companyId,
  marketplaceSku,
  days,
}: {
  companyId: string
  marketplaceSku: string
  days: BuyoutDays
}) {
  const query = useBuyoutDaily(companyId, marketplaceSku, days)

  if (query.status === 'pending') {
    return (
      <Card>
        <StatusPanel
          icon={<LineChartIcon aria-hidden="true" size={20} />}
          title="Загружаем динамику…"
        />
      </Card>
    )
  }

  if (query.status === 'error') {
    return (
      <Card tone="negative">
        <StatusPanel
          action={
            <Button
              onClick={() => {
                void query.refetch()
              }}
              size="compact"
              type="button"
              variant="secondary"
            >
              Повторить
            </Button>
          }
          description={
            query.error instanceof Error
              ? query.error.message
              : 'Попробуйте обновить страницу.'
          }
          icon={<CircleX aria-hidden="true" size={20} />}
          role="alert"
          title="Не удалось загрузить динамику"
          tone="negative"
        />
      </Card>
    )
  }

  if (query.data.series.length === 0) {
    return (
      <Card>
        <StatusPanel
          description="В выбранном периоде по этому артикулу нет заказов."
          icon={<LineChartIcon aria-hidden="true" size={20} />}
          title="Нет дневных данных"
        />
      </Card>
    )
  }

  const series = query.data.series
  const { actualDays, projectedDays } = countAvailableRateDays(series)

  return (
    <Card>
      <div className="flex flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h3 className="font-semibold">Динамика по дням</h3>
            <p className="text-xs text-text-muted">
              Факт появляется после созревания когорты, пунктир — текущий
              прогноз.
            </p>
          </div>
          <div className="flex items-center gap-3 text-xs text-text-secondary">
            <span className="inline-flex items-center gap-1.5">
              <span className="h-0.5 w-5 bg-accent-default" />
              Факт
            </span>
            <span className="inline-flex items-center gap-1.5">
              <span className="w-5 border-t-2 border-dashed border-warning-icon" />
              Прогноз
            </span>
          </div>
        </div>

        <p className="sr-only">
          Дневной график за {series.length} дней. Фактический процент доступен
          за {actualDays} дней, прогноз — за {projectedDays} дней.
        </p>
        <table className="sr-only">
          <caption>Значения дневного графика выкупа</caption>
          <thead>
            <tr>
              <th>Дата</th>
              <th>Факт</th>
              <th>Прогноз</th>
              <th>Разрешилось</th>
              <th>Заказано</th>
              <th>Прогноз количества</th>
            </tr>
          </thead>
          <tbody>
            {series.map((point) => (
              <tr key={point.date}>
                <td>{longDate(point.date)}</td>
                <td>{formatRateBps(point.actualBuyoutRateBps)}</td>
                <td>{formatRateBps(point.projectedBuyoutRateBps)}</td>
                <td>{formatRateBps(point.resolutionRateBps)}</td>
                <td>{QUANTITY.format(point.orderedQuantity)}</td>
                <td>
                  {point.projectedBuyoutQuantity === null ||
                  point.projectedBuyoutQuantity === undefined
                    ? 'Недостаточно данных'
                    : QUANTITY.format(point.projectedBuyoutQuantity)}
                </td>
              </tr>
            ))}
          </tbody>
        </table>

        <div
          className="h-64 w-full"
          role="img"
          aria-label="Динамика фактического и прогнозного процента выкупа"
        >
          <ResponsiveContainer height="100%" width="100%">
            <LineChart data={series} margin={{ left: 4, right: 12, top: 8 }}>
              <CartesianGrid
                stroke="var(--color-border-subtle)"
                vertical={false}
              />
              <XAxis
                axisLine={false}
                dataKey="date"
                tick={{ fill: 'var(--color-text-muted)', fontSize: 11 }}
                tickFormatter={shortDate}
                tickLine={false}
              />
              <YAxis
                axisLine={false}
                domain={[0, 10000]}
                tick={{ fill: 'var(--color-text-muted)', fontSize: 11 }}
                tickFormatter={(value: number) => formatRateBps(value)}
                tickLine={false}
                width={48}
              />
              <Tooltip content={<DailyTooltip />} />
              <Line
                connectNulls={false}
                dataKey="actualBuyoutRateBps"
                dot={actualDays === 1}
                name="Факт"
                stroke="var(--color-accent-default)"
                strokeWidth={2}
                type="monotone"
              />
              <Line
                connectNulls
                dataKey="projectedBuyoutRateBps"
                dot={projectedDays === 1}
                name="Прогноз"
                stroke="var(--color-warning-icon)"
                strokeDasharray="5 4"
                strokeWidth={2}
                type="monotone"
              />
            </LineChart>
          </ResponsiveContainer>
        </div>
      </div>
    </Card>
  )
}

function DailyTooltip({
  active,
  payload,
}: {
  active?: boolean
  payload?: readonly { payload?: DailyPoint }[]
}) {
  const point = payload?.[0]?.payload

  if (active !== true || point === undefined) {
    return null
  }

  return (
    <div className="rounded-md border border-border-default bg-surface-raised p-3 text-xs shadow-popover">
      <div className="mb-2 font-semibold">{longDate(point.date)}</div>
      <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
        <dt className="text-text-muted">Факт</dt>
        <dd className="text-right">
          {formatRateBps(point.actualBuyoutRateBps)}
        </dd>
        <dt className="text-text-muted">Прогноз</dt>
        <dd className="text-right">
          {formatRateBps(point.projectedBuyoutRateBps)}
        </dd>
        <dt className="text-text-muted">Разрешилось</dt>
        <dd className="text-right">{formatRateBps(point.resolutionRateBps)}</dd>
        <dt className="text-text-muted">Количество</dt>
        <dd className="text-right">
          {point.projectedBuyoutQuantity === null ||
          point.projectedBuyoutQuantity === undefined
            ? '—'
            : QUANTITY.format(point.projectedBuyoutQuantity)}{' '}
          из {QUANTITY.format(point.orderedQuantity)} шт
        </dd>
      </dl>
    </div>
  )
}

function shortDate(value: string): string {
  return new Intl.DateTimeFormat('ru-RU', {
    day: '2-digit',
    month: '2-digit',
  }).format(new Date(`${value}T00:00:00`))
}

function longDate(value: string): string {
  return new Intl.DateTimeFormat('ru-RU', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date(`${value}T00:00:00`))
}
