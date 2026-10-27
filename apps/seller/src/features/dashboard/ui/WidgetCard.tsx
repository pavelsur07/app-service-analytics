import { X } from 'lucide-react'
import type { ReactNode } from 'react'

import type { components } from '../../../api/schema'
import { sharedScale, sparklinePoints, sparklineTrend } from '../lib/sparkline'
import {
  changeView,
  formatValue,
  type ChangeTone,
  type MetricSpec,
  type WidgetSpec,
} from '../lib/widgets'

type Metrics = components['schemas']['DashboardMetricsResponse']
type Changes = components['schemas']['DashboardChangesResponse']
type Day = components['schemas']['DashboardDayResponse']

// Пары фон/текст/рамка — те же, что у Badge (UI Kit v0.6): зелёный —
// лучше, красный — хуже, серый — нейтрально.
const TONE: Record<ChangeTone, string> = {
  positive: 'border-positive-border bg-positive-bg text-positive-text',
  negative: 'border-negative-border bg-negative-bg text-negative-text',
  neutral: 'border-border-default bg-surface-sunken text-text-secondary',
}

function ChangeBadge({
  spec,
  changes,
  wide = false,
  reason,
}: {
  spec: MetricSpec
  changes: Changes
  wide?: boolean
  reason?: string
}) {
  const view = changeView(changes[spec.change], spec.kind, spec.good)

  return (
    <span
      className={`inline-flex items-center justify-center whitespace-nowrap rounded-md border px-1.5 py-0.5 text-xs font-semibold ${TONE[view.tone]} ${wide ? 'min-w-18' : ''}`}
      title={reason ?? view.title}
    >
      {reason === undefined ? view.text : '— нет базы'}
    </span>
  )
}

export interface WidgetCardProps {
  spec: WidgetSpec
  currency: string
  current: Metrics
  comparison: Metrics
  changes: Changes
  currentDays: readonly Day[]
  comparisonDays: readonly Day[]
  /** Пояснение под строками — например, что когорты выкупа ещё созревают. */
  hint: ReactNode
  onHide: () => void
}

export function WidgetCard({
  spec,
  currency,
  current,
  comparison,
  changes,
  currentDays,
  comparisonDays,
  hint,
  onHide,
}: WidgetCardProps) {
  const { primary } = spec
  const series = primary.series
  const currentSeries =
    series === null ? [] : availableSeries(currentDays, series)
  const comparisonSeries =
    series === null ? [] : availableSeries(comparisonDays, series)
  const scale = sharedScale(currentSeries, comparisonSeries)
  const noBase =
    spec.id === 'profit' &&
    typeof current.profitMinor === 'number' &&
    comparison.profitMinor === 0
  const graphLabel =
    currentSeries.length > 0
      ? `Динамика: ${spec.title}, к концу периода показатель ${sparklineTrend(currentSeries)}${comparisonSeries.length > 0 ? ', пунктир — период сравнения' : ''}`
      : `Динамика: ${spec.title}, только период сравнения (пунктир)`

  return (
    <section
      aria-label={spec.title}
      className="flex min-w-0 flex-col gap-3 rounded-xl border border-border-default bg-surface-raised p-4 shadow-card"
    >
      <div className="flex items-start gap-2">
        <h2 className="min-w-0 flex-1 text-sm leading-snug font-semibold">
          {spec.title}
        </h2>
        <button
          aria-label={`Скрыть виджет: ${spec.title}`}
          className="-mt-1 -mr-1.5 grid size-7 flex-none cursor-pointer place-items-center rounded-md text-text-disabled hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent-default"
          onClick={onHide}
          title="Скрыть виджет"
          type="button"
        >
          <X aria-hidden="true" size={14} />
        </button>
      </div>

      <div className="flex flex-col gap-1.5">
        <span className="text-xs text-text-muted">{primary.label}</span>
        <span className="text-2xl leading-tight font-semibold tracking-tight tabular-nums">
          {formatValue(current[primary.metric], primary.format, currency)}
        </span>
        <div className="flex flex-wrap items-center gap-2">
          <ChangeBadge
            changes={changes}
            spec={primary}
            {...(noBase
              ? {
                  reason:
                    'В периоде сравнения прибыль равна нулю — процент изменения не рассчитывается',
                }
              : {})}
          />
          <span className="text-xs text-text-muted tabular-nums">
            было{' '}
            {formatValue(comparison[primary.metric], primary.format, currency)}
          </span>
        </div>
      </div>

      {currentSeries.length === 0 && comparisonSeries.length === 0 ? null : (
        <svg
          aria-label={graphLabel}
          className="block h-10 w-full overflow-visible"
          preserveAspectRatio="none"
          role="img"
          viewBox="0 0 100 32"
        >
          {comparisonSeries.length === 0 ? null : (
            <polyline
              className="stroke-text-disabled"
              fill="none"
              points={sparklinePoints(comparisonSeries, scale)}
              strokeDasharray="3 3"
              strokeLinejoin="round"
              strokeWidth={1.5}
              vectorEffect="non-scaling-stroke"
            />
          )}
          {currentSeries.length === 0 ? null : (
            <polyline
              className="stroke-accent-default"
              fill="none"
              points={sparklinePoints(currentSeries, scale)}
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeWidth={2}
              vectorEffect="non-scaling-stroke"
            />
          )}
        </svg>
      )}

      {spec.rows.length === 0 ? null : (
        <div className="flex flex-col border-t border-border-subtle">
          {spec.rows.map((row) => (
            <div
              className="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-border-subtle py-2"
              key={row.metric}
            >
              <span className="flex-auto text-xs text-text-muted">
                {row.label}
              </span>
              {/* Значение и изменение переносятся вместе: в узкой карточке
                бейдж, оторванный от своего числа, читался бы как чужой. */}
              <span className="ml-auto flex items-center gap-2">
                <span className="text-sm font-medium tabular-nums">
                  {formatValue(current[row.metric], row.format, currency)}
                </span>
                <ChangeBadge changes={changes} spec={row} wide />
              </span>
            </div>
          ))}
        </div>
      )}

      {hint === null ? null : (
        <span className="text-xs leading-snug text-text-muted">{hint}</span>
      )}
    </section>
  )
}

/** Не соединяем известные точки через неизвестный день и не превращаем null в ноль. */
function availableSeries(
  days: readonly Day[],
  series: Exclude<keyof Day, 'date'>,
): number[] {
  const values = days.map((day) => day[series])
  return values.every((value): value is number => typeof value === 'number')
    ? values
    : []
}
